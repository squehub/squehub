<?php

declare(strict_types=1);

use App\Database\Schema\Schema;
use App\Database\Schema\Table;

/** Install optional MFA storage explicitly with `php squehub migrate`. */
final class CreateMfaTables
{
    public function up(PDO $pdo, Schema $schema): void
    {
        $schema->create('mfa_credentials', static function (Table $table): void {
            $table->id();
            $table->string('identity_digest', 64);
            $table->string('guard', 64);
            $table->string('identity_class', 255);
            $table->string('identity_kind', 1);
            $table->string('identity_identifier', 255);
            $table->boolean('enabled')->default(false);
            $table->text('encrypted_secret')->nullable();
            $table->string('secret_hash', 64)->nullable();
            $table->string('generation_nonce', 32)->nullable();
            $table->text('pending_secret')->nullable();
            $table->string('pending_secret_hash', 64)->nullable();
            $table->datetime('pending_expires_at')->nullable();
            // Fixed-width decimal preserves full 64-bit TOTP counters on MySQL,
            // whose portable integer() column is only a signed 32-bit INT.
            $table->string('last_counter', 20)->nullable();
            $table->unique('identity_digest', 'mfa_credentials_identity');
        });
        $schema->create('mfa_recovery_codes', static function (Table $table): void {
            $table->foreignId('credential_id');
            $table->string('code_hash', 64);
            $table->string('secret_hash', 64);
            $table->primary(['credential_id', 'code_hash']);
            $table->foreign('credential_id', 'mfa_credentials', 'id', 'mfa_recovery_credential_fk')
                ->onDelete('CASCADE');
        });
    }

    public function down(PDO $pdo, Schema $schema): void
    {
        $schema->dropIfExists('mfa_recovery_codes');
        $schema->dropIfExists('mfa_credentials');
    }
}
