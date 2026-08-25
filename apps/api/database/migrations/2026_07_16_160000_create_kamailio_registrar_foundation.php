<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            create or replace view kamailio_signaling_auth_view as
            select c.username, c.realm as domain, c.ha1
            from telephony_signaling_credentials c
            join telephony_sessions s on s.id = c.telephony_session_id
            where c.revoked_at is null
              and c.expires_at > now()
              and c.algorithm = 'MD5'
              and s.status = 'active'
              and s.expires_at > now()
            SQL);

        DB::statement(<<<'SQL'
            create table if not exists location (
                id bigserial primary key,
                ruid varchar(64) not null,
                username varchar(64) not null,
                domain varchar(128) default null,
                contact varchar(512) not null,
                received varchar(128) default null,
                path varchar(512) default null,
                expires timestamp without time zone not null,
                q real not null default 1.0,
                callid varchar(255) not null,
                cseq integer not null,
                last_modified timestamp without time zone not null default now(),
                flags integer not null default 0,
                cflags integer not null default 0,
                user_agent varchar(255) default null,
                socket varchar(64) default null,
                methods integer default null,
                instance varchar(255) default null,
                reg_id integer not null default 0,
                server_id integer not null default 0,
                connection_id integer not null default 0,
                keepalive integer not null default 0,
                partition integer not null default 0
            )
            SQL);
        DB::statement('create unique index if not exists location_ruid_unique on location (ruid)');
        DB::statement('create index if not exists location_account_idx on location (username, domain)');
        DB::statement('create index if not exists location_expires_idx on location (expires)');

        DB::statement(<<<'SQL'
            create table if not exists version (
                table_name varchar(64) not null primary key,
                table_version integer not null default 0
            )
            SQL);
        DB::statement("insert into version (table_name, table_version) values ('location', 9) on conflict (table_name) do update set table_version = excluded.table_version");
        DB::statement("insert into version (table_name, table_version) values ('kamailio_signaling_auth_view', 7) on conflict (table_name) do update set table_version = excluded.table_version");

        $this->createRoleFromEnvironment(
            'KAMAILIO_AUTH_DB_USER',
            'KAMAILIO_AUTH_DB_PASSWORD',
            'utcp_kamailio_auth_reader',
            [
                'grant select on kamailio_signaling_auth_view to %s',
                'grant select on version to %s',
            ],
        );
        $this->createRoleFromEnvironment(
            'KAMAILIO_USRLOC_DB_USER',
            'KAMAILIO_USRLOC_DB_PASSWORD',
            'utcp_kamailio_usrloc_writer',
            [
                'grant select, insert, update, delete on location to %s',
                'grant usage, select on sequence location_id_seq to %s',
                'grant select on version to %s',
            ],
        );
        $this->createRoleFromEnvironment(
            'KAMAILIO_OBSERVER_DB_USER',
            'KAMAILIO_OBSERVER_DB_PASSWORD',
            'utcp_kamailio_observer_reader',
            [
                'grant select on location to %s',
                'grant select on version to %s',
            ],
        );
        $this->createRoleFromEnvironment(
            'KAMAILIO_REGISTRATION_DB_USER',
            'KAMAILIO_REGISTRATION_DB_PASSWORD',
            'utcp_kamailio_registration_reader',
            [],
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('revoke all privileges on location from utcp_kamailio_auth_reader, utcp_kamailio_usrloc_writer, utcp_kamailio_observer_reader');
        DB::statement('revoke all privileges on kamailio_signaling_auth_view from utcp_kamailio_auth_reader, utcp_kamailio_usrloc_writer, utcp_kamailio_observer_reader');
        DB::statement('revoke all privileges on version from utcp_kamailio_auth_reader, utcp_kamailio_usrloc_writer, utcp_kamailio_observer_reader, utcp_kamailio_registration_reader');
        DB::statement('drop index if exists location_expires_idx');
        DB::statement('drop index if exists location_account_idx');
        DB::statement('drop index if exists location_ruid_unique');
        Schema::dropIfExists('location');

        DB::statement(<<<'SQL'
            create or replace view kamailio_signaling_auth_view as
            select c.username, c.realm as domain, c.ha1
            from telephony_signaling_credentials c
            join telephony_sessions s on s.id = c.telephony_session_id
            where c.revoked_at is null
              and c.expires_at > now()
              and s.status = 'active'
              and s.expires_at > now()
            SQL);
    }

    /**
     * @param  array<int, string>  $grantStatements
     */
    private function createRoleFromEnvironment(string $userEnv, string $passwordEnv, string $defaultUser, array $grantStatements): void
    {
        $user = env($userEnv, $defaultUser);
        $password = env($passwordEnv);
        if (! is_string($password) || trim($password) === '') {
            if (app()->environment('testing')) {
                $password = bin2hex(random_bytes(32));
            } else {
                throw new RuntimeException($passwordEnv.' must be set for Kamailio database role provisioning.');
            }
        }

        DB::statement(sprintf(
            'do $$ begin create role %s login password %s; exception when duplicate_object then alter role %s login password %s; end $$',
            $this->identifier($user),
            DB::getPdo()->quote($password),
            $this->identifier($user),
            DB::getPdo()->quote($password),
        ));
        foreach ($grantStatements as $statement) {
            DB::statement(sprintf($statement, $this->identifier($user)));
        }
    }

    private function identifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
};
