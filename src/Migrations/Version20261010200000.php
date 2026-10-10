<?php

declare(strict_types=1);

namespace Tmonier\SyliusTimelinePlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Written with the Schema API (not raw SQL) so that it runs on MySQL, MariaDB and PostgreSQL.
 */
final class Version20261010200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the timeline entry table (tmonier/sylius-timeline-plugin).';
    }

    public function up(Schema $schema): void
    {
        $entry = $schema->createTable('tmonier_timeline_entry');
        $entry->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $entry->addColumn('order_id', Types::INTEGER, ['notnull' => false]);
        $entry->addColumn('customer_id', Types::INTEGER, ['notnull' => false]);
        $entry->addColumn('admin_user_id', Types::INTEGER, ['notnull' => false]);
        $entry->addColumn('type', Types::STRING, ['length' => 32]);
        $entry->addColumn('workflow', Types::STRING, ['length' => 64, 'notnull' => false]);
        $entry->addColumn('transition', Types::STRING, ['length' => 64, 'notnull' => false]);
        $entry->addColumn('from_state', Types::STRING, ['length' => 64, 'notnull' => false]);
        $entry->addColumn('to_state', Types::STRING, ['length' => 64, 'notnull' => false]);
        $entry->addColumn('context', Types::JSON);
        $entry->addColumn('content', Types::TEXT, ['notnull' => false]);
        $entry->addColumn('pinned', Types::BOOLEAN);
        $entry->addColumn('author_type', Types::STRING, ['length' => 16]);
        $entry->addColumn('author_name', Types::STRING, ['length' => 255, 'notnull' => false]);
        $entry->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $entry->setPrimaryKey(['id']);
        $entry->addIndex(['order_id', 'created_at'], 'tmonier_timeline_entry_order_idx');
        $entry->addIndex(['customer_id', 'created_at'], 'tmonier_timeline_entry_customer_idx');
        $entry->addIndex(['admin_user_id'], 'IDX_B89C80526352511C');
        $entry->addForeignKeyConstraint('sylius_order', ['order_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_B89C80528D9F6D38');
        $entry->addForeignKeyConstraint('sylius_customer', ['customer_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_B89C80529395C3F3');
        $entry->addForeignKeyConstraint('sylius_admin_user', ['admin_user_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_B89C80526352511C');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('tmonier_timeline_entry');
    }
}
