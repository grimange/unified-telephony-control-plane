// utcp exposes only the explicit disposable-database migration verifier in E1.
// No API, executor, scheduler or automatic migration starts from this binary.
package main

import (
	"context"
	"fmt"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"

	"github.com/grimange/unified-telephony-control-plane/apps/control-plane/internal/postgres"
)

func main() {
	if len(os.Args) != 2 || os.Args[1] != "migrate-test" || !strings.HasPrefix(os.Getenv("UTCP_CP1_RUN"), "utcp-go-cp1-") {
		fmt.Fprintln(os.Stderr, "usage: explicit disposable GO-CP1 verifier only")
		os.Exit(2)
	}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	if err := postgres.MigrateTest(ctx, os.Getenv("UTCP_CP1_DSN"), "migrations"); err != nil {
		// Connection errors can contain credentials: diagnostics remain deliberately bounded.
		fmt.Fprintln(os.Stderr, "disposable migration verification failed")
		os.Exit(1)
	}
	fmt.Println("reviewed additive Goose migration applied to disposable database")
}
