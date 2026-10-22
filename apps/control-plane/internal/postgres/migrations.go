package postgres

import (
	"context"
	"database/sql"
	_ "github.com/jackc/pgx/v5/stdlib"
	"github.com/pressly/goose/v3"
)

// MigrateTest applies only additive GO-CP1 migrations to an explicitly supplied
// disposable database after Laravel history. It does not adopt or replay that
// history; no production startup path calls this helper.
func MigrateTest(ctx context.Context, dsn, dir string) error {
	db, e := sql.Open("pgx", dsn)
	if e != nil {
		return e
	}
	defer db.Close()
	if e = db.PingContext(ctx); e != nil {
		return e
	}
	if e = goose.SetDialect("postgres"); e != nil {
		return e
	}
	return goose.UpContext(ctx, db, dir)
}
