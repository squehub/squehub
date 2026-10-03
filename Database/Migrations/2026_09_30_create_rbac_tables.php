<?php

declare(strict_types=1);

use App\Database\Schema\Schema;
use App\Database\Schema\Table;

/** Install persistent RBAC explicitly with `php squehub migrate`. */
final class CreateRbacTables
{
    public function up(PDO $pdo, Schema $schema): void
    {
        $schema->create('rbac_roles', static function (Table $table): void {
            $table->id();
            $table->string('key', 128);
            $table->string('key_digest', 64);
            $table->unique('key_digest', 'rbac_roles_digest');
        });
        $schema->create('rbac_permissions', static function (Table $table): void {
            $table->id();
            $table->string('key', 128);
            $table->string('key_digest', 64);
            $table->unique('key_digest', 'rbac_permissions_digest');
        });
        $schema->create('rbac_role_permissions', static function (Table $table): void {
            $table->foreignId('role_id');
            $table->foreignId('permission_id');
            $table->primary(['role_id', 'permission_id']);
            $table->index('permission_id', 'rbac_role_permissions_permission');
            $table->foreign('role_id', 'rbac_roles', 'id', 'rbac_rp_role_fk')->onDelete('CASCADE');
            $table->foreign('permission_id', 'rbac_permissions', 'id', 'rbac_rp_permission_fk')->onDelete('CASCADE');
        });
        $schema->create('rbac_identity_roles', static function (Table $table): void {
            $table->string('identity_class', 255);
            $table->string('identity_kind', 1);
            $table->string('identity_identifier', 255);
            $table->string('identity_digest', 64);
            $table->foreignId('role_id');
            $table->primary(['identity_digest', 'role_id']);
            $table->index('role_id', 'rbac_identity_roles_role');
            $table->foreign('role_id', 'rbac_roles', 'id', 'rbac_ir_role_fk')->onDelete('CASCADE');
        });
    }

    public function down(PDO $pdo, Schema $schema): void
    {
        $schema->dropIfExists('rbac_identity_roles');
        $schema->dropIfExists('rbac_role_permissions');
        $schema->dropIfExists('rbac_permissions');
        $schema->dropIfExists('rbac_roles');
    }
}
