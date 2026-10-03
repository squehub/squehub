<?php

declare(strict_types=1);

use App\Database\Schema\Schema;
use App\Database\Schema\Table;

/** Install optional persistent browser credentials explicitly with `php squehub migrate`. */
final class CreateRememberTokens
{
    public function up(PDO $pdo, Schema $schema): void
    {
        $schema->create('remember_tokens', static function (Table $table): void {
            $table->string('selector', 22);
            $table->string('validator_hash', 64);
            $table->string('guard', 64);
            $table->string('identity_identifier', 255);
            $table->string('identity_scope_hash', 64);
            $table->string('credential_fingerprint', 64);
            $table->datetime('created_at');
            $table->datetime('expires_at');
            $table->primary('selector');
            $table->index('identity_scope_hash', 'remember_tokens_identity');
            $table->index('expires_at', 'remember_tokens_expires');
        });
    }

    public function down(PDO $pdo, Schema $schema): void
    {
        $schema->dropIfExists('remember_tokens');
    }
}
