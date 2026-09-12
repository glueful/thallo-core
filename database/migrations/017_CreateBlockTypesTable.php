<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateBlockTypesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('block_types')) {
            return;
        }
        $schema->createTable('block_types', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            // Immutable after create (spec §1): the blocks/{slug}.twig template contract.
            $table->string('slug', 64);
            $table->string('label', 120);
            $table->string('icon', 64)->nullable();
            // Free-form picker grouping ("Layout", "Content", …) — presentation-level
            // metadata only; NOTHING branches on the value. Null groups under "Other".
            $table->string('category', 64)->nullable();
            $table->string('description', 500)->nullable();
            // Field-definition list, same JSON shape AND column type as content_types.schema.
            $table->json('schema');
            // Deactivate over delete (spec §2): inactive = hidden from the picker only.
            $table->boolean('active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique('slug', 'uniq_block_type_slug');
            $table->unique('uuid');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('block_types');
    }

    public function getDescription(): string
    {
        return 'Create block_types (global block-type registry for blocks fields).';
    }
}
