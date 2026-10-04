<?php

declare(strict_types=1);

use App\Database\Schema\Schema;
use App\Database\Schema\Table;

/** Install persistent personal access tokens explicitly with `php squehub migrate`. */
final class CreateApiTokens
{
    public function up(PDO $pdo, Schema $schema): void
    {
        $schema->create('api_tokens', static function (Table $table): void {
            $table->string('identifier', 16);
            $table->string('token_hash', 64);
            $table->string('guard', 64);
            $table->string('identity_kind', 1);
            $table->string('identity_identifier', 255);
            $table->string('name', 128);
            $table->text('abilities');
            $table->datetime('created_at');
            $table->datetime('expires_at')->nullable();
            $table->datetime('last_used_at')->nullable();
            $table->datetime('revoked_at')->nullable();
            $table->unique('identifier', 'api_tokens_identifier');
            $table->unique('token_hash', 'api_tokens_hash');
            $table->index(['guard', 'identity_kind', 'identity_identifier'], 'api_tokens_identity');
            $table->index('expires_at', 'api_tokens_expires');
            $table->index('revoked_at', 'api_tokens_revoked');
        });
    }

    public function down(PDO $pdo, Schema $schema): void
    {
        $schema->dropIfExists('api_tokens');
    }
}
