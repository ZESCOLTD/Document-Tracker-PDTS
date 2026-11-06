<?php

namespace App\Helpers;

use App\Models\SheqDocumentRibbon;

class RibbonHelper
{
    public static function get($pageKey)
    {
        return SheqDocumentRibbon::where('page_key', $pageKey)
            ->where('is_active', true)
            ->first();
    }
}
