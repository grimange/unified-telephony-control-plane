package executor

import (
	"bufio"
	"context"
	"encoding/json"
	"fmt"
	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/infrastructure"
	"io"
	"os"
	"path/filepath"
	"reflect"
	"syscall"
	"time"
)

const maxJournalBytes = 64 << 20
const maxRecordBytes = 32768

type Entry struct {
	Grant     Grant  `json:"grant"`
	State     string `json:"state"`
	APICode   int    `json:"api_code"`
	APIReason string `json:"api_reason"`
}
type record struct {
	Sequence uint64 `json:"sequence"`
	Previous string `json:"previous"`
	Entry    Entry  `json:"entry"`
	Hash     string `json:"hash"`
}
type head struct {
	StoreID  string `json:"store_id"`
	Sequence uint64 `json:"sequence"`
	Hash     string `json:"hash"`
}

// Journal uses a stable, never-renamed flock inode, a checksummed append-only
// WAL and an atomically replaced durable head. Any inconsistent crash tail is
// quarantined by refusing Open and every subsequent operation. No truncation,
// repair, compaction or automatic initialization is performed.
type Journal struct {
	dir, id string
	lock    *os.File
	Hook    func(string)
}

func (j *Journal) hook(s string) {
	if j.Hook != nil {
		j.Hook(s)
	}
}
func Init(dir, id string) (*Journal, error) {
	if id == "" || len(id) > 128 {
		return nil, ErrStorage
	}
	if e := os.Mkdir(dir, 0700); e != nil {
		return nil, ErrStorage
	}
	// Initialization requires a new directory, not merely a missing journal file.
	for _, name := range []string{"lock", "wal"} {
		f, e := os.OpenFile(filepath.Join(dir, name), os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
		if e != nil {
			return nil, ErrStorage
		}
		e = f.Sync()
		_ = f.Close()
		if e != nil {
			return nil, ErrStorage
		}
	}
	b, _ := json.Marshal(head{StoreID: id})
	if e := os.WriteFile(filepath.Join(dir, "head"), b, 0600); e != nil {
		return nil, ErrStorage
	}
	f, e := os.OpenFile(filepath.Join(dir, "head"), os.O_RDONLY, 0)
	if e != nil {
		return nil, ErrStorage
	}
	e = f.Sync()
	_ = f.Close()
	if e != nil {
		return nil, ErrStorage
	}
	if syncDir(dir) != nil || syncDir(filepath.Dir(dir)) != nil {
		return nil, ErrStorage
	}
	return Open(dir, id)
}
func Open(dir, id string) (*Journal, error) {
	d, e := os.Lstat(dir)
	if e != nil || !d.IsDir() || d.Mode().Perm() != 0700 {
		return nil, ErrStorage
	}
	f, e := openPrivate(filepath.Join(dir, "lock"), os.O_RDWR)
	if e != nil {
		return nil, ErrStorage
	}
	j := &Journal{dir: dir, id: id, lock: f}
	e = j.withLock(context.Background(), func(map[string]Entry, head) error { return nil })
	if e != nil {
		_ = f.Close()
		return nil, e
	}
	return j, nil
}
func (j *Journal) Close() error { return j.lock.Close() }
func openPrivate(path string, flags int) (*os.File, error) {
	fd, e := syscall.Open(path, flags|syscall.O_NOFOLLOW|syscall.O_CLOEXEC, 0)
	if e != nil {
		return nil, ErrStorage
	}
	f := os.NewFile(uintptr(fd), path)
	s, e := f.Stat()
	if e != nil || !s.Mode().IsRegular() || s.Mode().Perm() != 0600 {
		_ = f.Close()
		return nil, ErrStorage
	}
	st, ok := s.Sys().(*syscall.Stat_t)
	if !ok || st.Uid != uint32(os.Geteuid()) || st.Nlink != 1 {
		_ = f.Close()
		return nil, ErrStorage
	}
	return f, nil
}
func syncDir(path string) error {
	f, e := os.Open(path)
	if e != nil {
		return e
	}
	defer f.Close()
	return f.Sync()
}
func (j *Journal) withLock(ctx context.Context, fn func(map[string]Entry, head) error) error {
	// Separate file descriptions are needed even within one Go process; flock on
	// a shared description does not arbitrate concurrent goroutines.
	f, e := openPrivate(filepath.Join(j.dir, "lock"), os.O_RDWR)
	if e != nil {
		return ErrStorage
	}
	defer f.Close()
	original, e := j.lock.Stat()
	if e != nil {
		return ErrStorage
	}
	actual, e := f.Stat()
	if e != nil || !os.SameFile(original, actual) {
		return ErrStorage
	}
	for {
		if ctx.Err() != nil {
			return ctx.Err()
		}
		e = syscall.Flock(int(f.Fd()), syscall.LOCK_EX|syscall.LOCK_NB)
		if e == nil {
			break
		}
		if e != syscall.EWOULDBLOCK {
			return ErrStorage
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(time.Millisecond):
		}
	}
	defer syscall.Flock(int(f.Fd()), syscall.LOCK_UN)
	// Revalidate the path while holding the inode lock, never replace it.
	now, e := os.Lstat(filepath.Join(j.dir, "lock"))
	if e != nil || !os.SameFile(actual, now) {
		return ErrStorage
	}
	entries, h, e := j.load()
	if e != nil {
		return e
	}
	return fn(entries, h)
}
func recordHash(r record) string { r.Hash = ""; b, _ := json.Marshal(r); return Hash(b) }
func (j *Journal) load() (map[string]Entry, head, error) {
	fail := func() (map[string]Entry, head, error) { return nil, head{}, ErrStorage }
	hf, e := openPrivate(filepath.Join(j.dir, "head"), os.O_RDONLY)
	if e != nil {
		return fail()
	}
	hb, e := io.ReadAll(io.LimitReader(hf, 2049))
	_ = hf.Close()
	if e != nil || len(hb) > 2048 {
		return fail()
	}
	var h head
	if strict(hb, &h) != nil || h.StoreID != j.id {
		return fail()
	}
	wf, e := openPrivate(filepath.Join(j.dir, "wal"), os.O_RDONLY)
	if e != nil {
		return fail()
	}
	defer wf.Close()
	s, e := wf.Stat()
	if e != nil || s.Size() > maxJournalBytes {
		return fail()
	}
	entries := map[string]Entry{}
	scanner := bufio.NewScanner(wf)
	scanner.Buffer(make([]byte, 4096), maxRecordBytes)
	seq := uint64(0)
	prev := ""
	total := int64(0)
	for scanner.Scan() {
		line := scanner.Bytes()
		total += int64(len(line) + 1)
		var r record
		if strict(line, &r) != nil || r.Sequence != seq+1 || r.Previous != prev || r.Hash != recordHash(r) || validateGrant(r.Entry.Grant) != nil {
			return fail()
		}
		old, exists := entries[r.Entry.Grant.DispatchID]
		if !validTransition(old, r.Entry, exists) {
			return fail()
		}
		entries[r.Entry.Grant.DispatchID] = r.Entry
		seq = r.Sequence
		prev = r.Hash
	}
	if scanner.Err() != nil || total != s.Size() || h.Sequence != seq || h.Hash != prev {
		return fail()
	}
	return entries, h, nil
}
func strict(b []byte, v any) error { return infrastructure.DecodeStrict(b, v) }
func validTransition(old, n Entry, exists bool) bool {
	if !exists {
		return n.State == "accepted" && n.APICode == 0 && n.APIReason == ""
	}
	if !reflect.DeepEqual(old.Grant, n.Grant) {
		return false
	}
	switch old.State {
	case "accepted":
		return (n.State == "possible" && old.Grant.Phase == "consumed" || n.State == "closed") && n.APICode == 0 && n.APIReason == ""
	case "possible":
		return n.State == "succeeded" && n.APICode >= 200 && n.APICode < 300 && n.APIReason == "" || n.State == "api_rejected" && definitive(n.APICode, n.APIReason)
	default:
		return false
	}
}
func (j *Journal) append(h head, e Entry) error {
	r := record{Sequence: h.Sequence + 1, Previous: h.Hash, Entry: e}
	r.Hash = recordHash(r)
	b, _ := json.Marshal(r)
	b = append(b, '\n')
	if len(b) > maxRecordBytes {
		return ErrStorage
	}
	f, err := openPrivate(filepath.Join(j.dir, "wal"), os.O_WRONLY|os.O_APPEND)
	if err != nil {
		return err
	}
	defer f.Close()
	s, err := f.Stat()
	if err != nil || s.Size()+int64(len(b)) > maxJournalBytes {
		return ErrStorage
	}
	j.hook("before_wal_write")
	n, err := f.Write(b)
	if err != nil || n != len(b) {
		return ErrStorage
	}
	j.hook("before_wal_sync")
	if f.Sync() != nil {
		return ErrStorage
	}
	j.hook("after_wal_sync")
	hb, _ := json.Marshal(head{j.id, r.Sequence, r.Hash})
	tmp, err := os.CreateTemp(j.dir, ".head-")
	if err != nil {
		return ErrStorage
	}
	tmpName := tmp.Name()
	defer os.Remove(tmpName)
	if tmp.Chmod(0600) != nil {
		_ = tmp.Close()
		return ErrStorage
	}
	n, err = tmp.Write(hb)
	if err != nil || n != len(hb) {
		_ = tmp.Close()
		return ErrStorage
	}
	err = tmp.Sync()
	_ = tmp.Close()
	if err != nil {
		return ErrStorage
	}
	j.hook("before_head_replace")
	if os.Rename(tmpName, filepath.Join(j.dir, "head")) != nil || syncDir(j.dir) != nil {
		return ErrStorage
	}
	j.hook("after_commit")
	return nil
}
func (j *Journal) Accept(ctx context.Context, raw []byte, v GrantVerifier, peerSAN string) (out Entry, err error) {
	g, e := v.Verify(raw, peerSAN)
	if e != nil {
		return out, e
	}
	if g.JournalID != j.id {
		return out, ErrDenied
	}
	err = j.withLock(ctx, func(es map[string]Entry, h head) error {
		if old, ok := es[g.DispatchID]; ok {
			if !reflect.DeepEqual(old.Grant, g) {
				return ErrDenied
			}
			out = old
			return nil
		}
		out = Entry{Grant: g, State: "accepted"}
		return j.append(h, out)
	})
	return
}
func (j *Journal) Snapshot(ctx context.Context, id string) (out Entry, err error) {
	err = j.withLock(ctx, func(es map[string]Entry, _ head) error {
		var ok bool
		out, ok = es[id]
		if !ok {
			return ErrDenied
		}
		return nil
	})
	return
}
func (j *Journal) CloseGrant(ctx context.Context, id string) (out Entry, err error) {
	j.hook("before_closure")
	err = j.withLock(ctx, func(es map[string]Entry, h head) error {
		var ok bool
		out, ok = es[id]
		if !ok {
			return ErrDenied
		}
		if out.State == "closed" || out.State == "api_rejected" {
			return nil
		}
		if out.State != "accepted" {
			return ErrUncertain
		}
		out.State = "closed"
		if e := j.append(h, out); e != nil {
			return e
		}
		j.hook("after_closure")
		return nil
	})
	return
}
func (j *Journal) String() string { return fmt.Sprintf("executor journal %s", j.id) }
