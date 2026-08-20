<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Comprueba que el esquema tiene las nueve tablas del diseno con los nombres
 * exactos acordados y las columnas clave, incluida la referencia polimorfica de
 * la bitacora de auditoria.
 */
final class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<string>> */
    private const REQUIRED_COLUMNS = [
        'prospects' => ['full_name', 'monthly_income'],
        'identity_documents' => ['prospect_id', 'document_type', 'ocr_result'],
        'identity_validations' => ['prospect_id', 'ine_status', 'renapo_status'],
        'credit_applications' => ['prospect_id', 'credit_type', 'application_status'],
        'credit_simulations' => ['proposed_amount', 'estimated_monthly_payment'],
        'customers' => ['prospect_id', 'customer_number'],
        'credit_lines' => ['customer_id', 'authorized_amount', 'line_status'],
        'cards' => ['customer_id', 'tokenized_card_number', 'card_status'],
        'audit_logs' => [
            'prospect_id',
            'affected_entity',
            'affected_entity_id',
            'event_type',
            'actor',
            'ip_address',
            'event_at',
            'previous_hash',
            'current_hash',
        ],
    ];

    public function test_the_nine_design_tables_exist(): void
    {
        foreach (array_keys(self::REQUIRED_COLUMNS) as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Falta la tabla del diseno: {$table}"
            );
        }
    }

    public function test_key_columns_use_the_agreed_names(): void
    {
        foreach (self::REQUIRED_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "Falta la columna {$table}.{$column}"
                );
            }
        }
    }

    public function test_audit_log_uses_a_polymorphic_reference_instead_of_one_key_per_entity(): void
    {
        // El patron exige exactamente una llave foranea (el ancla prospect_id) y
        // ninguna columna *_id adicional por entidad auditada.
        $forbidden = [
            'identity_document_id',
            'identity_validation_id',
            'credit_application_id',
            'credit_simulation_id',
            'customer_id',
            'credit_line_id',
            'card_id',
        ];

        foreach ($forbidden as $column) {
            $this->assertFalse(
                Schema::hasColumn('audit_logs', $column),
                "audit_logs no debe tener {$column}: la referencia es polimorfica"
            );
        }
    }
}
