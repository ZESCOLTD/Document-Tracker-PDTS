<?php

namespace App\Contracts;

class ContractTypeRules
{
    /**
     * All supported contract types and their rules.
     */
    protected static array $types = [

        /*
        |--------------------------------------------------------------------------
        | Lease Agreement
        |--------------------------------------------------------------------------
        */

        'lease' => [

            'label' => 'Lease',

            'description' =>
                'An agreement under which ZESCO leases property, land, premises or another asset.',

            'sections' => [

                'request' => true,
                'asset' => true,
                'justification' => true,
                'counterparty' => true,
                'commercial' => true,
                'risk' => true,
                'attachments' => true,

            ],

            'fields' => [

                'land_asset_number' => [
                    'visible' => true,
                    'required' => true,
                    'label' => 'Land / Asset',
                ],

                'estimated_contract_value' => [
                    'visible' => true,
                    'required' => true,
                    'label' => 'Estimated Contract Value',
                ],

                'money_currency' => [
                    'visible' => true,
                    'required' => true,
                    'label' => 'Currency',
                ],

                'contract_duration' => [
                    'visible' => true,
                    'required' => true,
                    'label' => 'Lease Duration',
                ],

                'funding_source' => [
                    'visible' => true,
                    'required' => false,
                    'label' => 'Funding Source',
                ],

                'budget_code' => [
                    'visible' => true,
                    'required' => false,
                    'label' => 'Budget Code',
                ],

                'cost_centre' => [
                    'visible' => true,
                    'required' => false,
                    'label' => 'Cost Centre',
                ],

                'procurement_reference' => [
                    'visible' => false,
                    'required' => false,
                    'label' => 'Procurement Reference',
                ],

                'proposed_payment_structure' => [
                    'visible' => true,
                    'required' => true,
                    'label' => 'Proposed Payment Structure',
                ],

            ],

            /*
            |--------------------------------------------------------------------------
            | Required Supporting Documents
            |--------------------------------------------------------------------------
            */

            'attachments' => [

                'business_case' => [
                    'label' => 'Business Case',
                    'required' => true,
                ],

                'asset_documents' => [
                    'label' => 'Asset / Property Documents',
                    'required' => true,
                ],

                'valuation_report' => [
                    'label' => 'Valuation Report',
                    'required' => false,
                ],

                'other' => [
                    'label' => 'Other Supporting Documents',
                    'required' => false,
                ],

            ],

        ],


        /*
        |--------------------------------------------------------------------------
        | Contract of Sale
        |--------------------------------------------------------------------------
        */

        'contract_of_sale' => [

            'label' => 'Contract',

            'description' =>
                'An agreement relating to the purchase or sale of an asset, property or other item.',

            'sections' => [

                'request' => true,
                'asset' => true,
                'justification' => true,
                'counterparty' => true,
                'commercial' => true,
                'risk' => true,
                'attachments' => true,

            ],

            'fields' => [

                'land_asset_number' => [
                    'visible' => true,
                    'required' => true,
                    'label' => 'Land / Asset',
                ],

                'estimated_contract_value' => [
                    'visible' => true,
                    'required' => true,
                    'label' => 'Sale / Purchase Value',
                ],

                'money_currency' => [
                    'visible' => true,
                    'required' => true,
                    'label' => 'Currency',
                ],

                'contract_duration' => [
                    'visible' => false,
                    'required' => false,
                    'label' => 'Contract Duration',
                ],

                'funding_source' => [
                    'visible' => true,
                    'required' => false,
                    'label' => 'Funding Source',
                ],

                'budget_code' => [
                    'visible' => true,
                    'required' => false,
                    'label' => 'Budget Code',
                ],

                'cost_centre' => [
                    'visible' => true,
                    'required' => false,
                    'label' => 'Cost Centre',
                ],

                'procurement_reference' => [
                    'visible' => false,
                    'required' => false,
                    'label' => 'Procurement Reference',
                ],

                'proposed_payment_structure' => [
                    'visible' => true,
                    'required' => true,
                    'label' => 'Payment Structure',
                ],

            ],

            'attachments' => [

                'business_case' => [
                    'label' => 'Business Case',
                    'required' => true,
                ],

                'asset_documents' => [
                    'label' => 'Asset / Property Documents',
                    'required' => true,
                ],

                'valuation_report' => [
                    'label' => 'Valuation Report',
                    'required' => true,
                ],

                'other' => [
                    'label' => 'Other Supporting Documents',
                    'required' => false,
                ],

            ],

        ],

    ];


    /*
    |--------------------------------------------------------------------------
    | All Contract Types
    |--------------------------------------------------------------------------
    */

    public static function all(): array
    {
        return self::$types;
    }


    /*
    |--------------------------------------------------------------------------
    | Contract Type Exists
    |--------------------------------------------------------------------------
    */

    public static function exists(?string $type): bool
    {
        return $type !== null
            && isset(self::$types[$type]);
    }


    /*
    |--------------------------------------------------------------------------
    | Get Contract Type
    |--------------------------------------------------------------------------
    */

    public static function get(?string $type): array
    {
        return self::$types[$type] ?? [];
    }


    /*
    |--------------------------------------------------------------------------
    | Get Label
    |--------------------------------------------------------------------------
    */

    public static function label(?string $type): ?string
    {
        return self::$types[$type]['label'] ?? null;
    }


    /*
    |--------------------------------------------------------------------------
    | Get Description
    |--------------------------------------------------------------------------
    */

    public static function description(?string $type): ?string
    {
        return self::$types[$type]['description'] ?? null;
    }


    /*
    |--------------------------------------------------------------------------
    | Section Visibility
    |--------------------------------------------------------------------------
    */

    public static function sectionVisible(
        ?string $type,
        string $section
    ): bool {
        return self::$types[$type]['sections'][$section]
            ?? false;
    }


    /*
    |--------------------------------------------------------------------------
    | Field Configuration
    |--------------------------------------------------------------------------
    */

    public static function field(
        ?string $type,
        string $field
    ): array {
        return self::$types[$type]['fields'][$field]
            ?? [
                'visible' => false,
                'required' => false,
            ];
    }


    /*
    |--------------------------------------------------------------------------
    | Field Visible
    |--------------------------------------------------------------------------
    */

    public static function fieldVisible(
        ?string $type,
        string $field
    ): bool {
        return self::field($type, $field)['visible']
            ?? false;
    }


    /*
    |--------------------------------------------------------------------------
    | Field Required
    |--------------------------------------------------------------------------
    */

    public static function fieldRequired(
        ?string $type,
        string $field
    ): bool {
        return self::field($type, $field)['required']
            ?? false;
    }


    /*
    |--------------------------------------------------------------------------
    | Field Label
    |--------------------------------------------------------------------------
    */

    public static function fieldLabel(
        ?string $type,
        string $field
    ): ?string {
        return self::field($type, $field)['label']
            ?? null;
    }


    /*
    |--------------------------------------------------------------------------
    | Attachment Rules
    |--------------------------------------------------------------------------
    */

    public static function attachments(?string $type): array
    {
        return self::$types[$type]['attachments']
            ?? [];
    }


    /*
    |--------------------------------------------------------------------------
    | Required Attachments
    |--------------------------------------------------------------------------
    */

    public static function requiredAttachments(?string $type): array
    {
        return collect(self::attachments($type))
            ->filter(fn ($attachment) =>
                ($attachment['required'] ?? false) === true
            )
            ->all();
    }


    /*
    |--------------------------------------------------------------------------
    | Required Attachment Keys
    |--------------------------------------------------------------------------
    */

    public static function requiredAttachmentKeys(?string $type): array
    {
        return array_keys(
            self::requiredAttachments($type)
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Field List
    |--------------------------------------------------------------------------
    */

    public static function fields(?string $type): array
    {
        return self::$types[$type]['fields']
            ?? [];
    }


    /*
    |--------------------------------------------------------------------------
    | Visible Fields
    |--------------------------------------------------------------------------
    */

    public static function visibleFields(?string $type): array
    {
        return collect(self::fields($type))
            ->filter(fn ($field) =>
                ($field['visible'] ?? false) === true
            )
            ->all();
    }


    /*
    |--------------------------------------------------------------------------
    | Required Fields
    |--------------------------------------------------------------------------
    */

    public static function requiredFields(?string $type): array
    {
        return collect(self::fields($type))
            ->filter(fn ($field) =>
                ($field['required'] ?? false) === true
            )
            ->all();
    }
}
