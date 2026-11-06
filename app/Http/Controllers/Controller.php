<?php

namespace App\Http\Controllers;

use App\Models\ArticleView;
use App\Models\ExpenseAccountView;
use App\Models\PhrisUserDetails;
use App\Models\SupplierView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

abstract class Controller
{
    public function getManNumbers(Request $request)
    {
        $search = strtolower(trim($request->search));
        $page = (int) $request->page;

        $resultCount = 30;

        $offset = ($page - 1) * $resultCount;

        if ($search == '') {
            $dataset = PhrisUserDetails::select('con_per_no', 'name')
                ->where('con_st_code', 'ACT')
                ->whereNull('alt_per_no')
//                ->where(function ($query) {
//                    $query->where(function ($query) {
//                        $query->where('contract_type', 'FIXED CONTRACT')
//                            ->where('con_per_no', 'like', 'C7%');
//                    })->orWhere(function ($query) {
//                        $query->where('contract_type', 'PERMANENT CONTRACT')
//                            ->where('con_per_no', '>=', '70000');
//                    });
//                })
//                ->orderby('con_per_no', 'asc')
                ->skip($offset)
                ->take($resultCount)
                ->get();

            $count = PhrisUserDetails::count();
        } else {
            $dataset = PhrisUserDetails::select('con_per_no', 'name')
                ->where('con_st_code', 'ACT')
                ->whereNull('alt_per_no')
                ->where(function ($query) use ($search) {
                    $query->where(DB::raw('lower(con_per_no)'), 'like', '%'.$search.'%')
                        ->orWhere(DB::raw('lower(name)'), 'like', '%'.$search.'%');
                })
                ->orderby('con_per_no', 'asc')
                ->skip($offset)
                ->take($resultCount)
                ->get();

            $count = PhrisUserDetails::select('con_per_no', 'name')
                ->where('con_st_code', 'ACT')
                ->whereNull('alt_per_no')
                ->where(function ($query) use ($search) {
                    $query->where(DB::raw('lower(con_per_no)'), 'like', '%'.$search.'%')
                        ->orWhere(DB::raw('lower(name)'), 'like', '%'.$search.'%');
                })->count();

        }

        $endCount = $offset + $resultCount;
        $morePages = $count > $endCount;

        $results = [];
        foreach ($dataset as $item) {
            $results[] = [
                'id' => $item->con_per_no,
                'text' => $item->con_per_no.':'.$item->name,
            ];
        }

        $response = [
            'results' => $results,
            'pagination' => ['more' => $morePages],
        ];

        return response()->json($response);
    }

    public function getArticles(Request $request)
    {
        $search = strtolower(trim($request->search));
        $page = (int) $request->page;

        $resultCount = 30;

        $offset = ($page - 1) * $resultCount;

        if ($search == '') {
            $dataset = ArticleView::query()
                ->skip($offset)
                ->take($resultCount)
                ->get();

            $count = ArticleView::count();
        } else {
            $dataset = ArticleView::query()

                ->where(function ($query) use ($search) {
                    $query->where(DB::raw('lower(code_article)'), 'like', '%'.$search.'%')
                        ->orWhere(DB::raw('lower(description)'), 'like', '%'.$search.'%');
                })
                ->orderby('code_article', 'asc')
                ->skip($offset)
                ->take($resultCount)
                ->get();

            $count = ArticleView::query()
                ->where(function ($query) use ($search) {
                    $query->where(DB::raw('lower(code_article)'), 'like', '%'.$search.'%')
                        ->orWhere(DB::raw('lower(description)'), 'like', '%'.$search.'%');
                })->count();

        }

        $endCount = $offset + $resultCount;
        $morePages = $count > $endCount;

        $results = [];
        foreach ($dataset as $item) {
            $results[] = [
                'id' => $item->code_article,
                'text' => $item->code_article.':'.$item->description,
            ];
        }

        $response = [
            'results' => $results,
            'pagination' => ['more' => $morePages],
        ];

        return response()->json($response);
    }

    public function getSuppliers(Request $request)
    {
        $search = strtolower(trim($request->search));
        $page = (int) $request->page;

        $resultCount = 30;

        $offset = ($page - 1) * $resultCount;

        if ($search == '') {
            $dataset = SupplierView::query()
                ->skip($offset)
                ->take($resultCount)
                ->get();

            $count = SupplierView::count();
        } else {
            $dataset = SupplierView::query()
                ->where(function ($query) use ($search) {
                    $query->where(DB::raw('lower(code_supplier)'), 'like', '%'.$search.'%')
                        ->orWhere(DB::raw('lower(name_supplier)'), 'like', '%'.$search.'%');
                })
                ->orderby('code_supplier', 'asc')
                ->skip($offset)
                ->take($resultCount)
                ->get();

            $count = SupplierView::query()
                ->where(function ($query) use ($search) {
                    $query->where(DB::raw('lower(code_supplier)'), 'like', '%'.$search.'%')
                        ->orWhere(DB::raw('lower(name_supplier)'), 'like', '%'.$search.'%');
                })->count();

        }

        $endCount = $offset + $resultCount;
        $morePages = $count > $endCount;

        $results = [];
        foreach ($dataset as $item) {
            $results[] = [
                'id' => $item->code_supplier,
                'text' => $item->code_supplier.':'.$item->name_supplier,
            ];
        }

        $response = [
            'results' => $results,
            'pagination' => ['more' => $morePages],
        ];

        return response()->json($response);
    }

    public function getExpenseAccounts(Request $request)
    {
        $search = strtolower(trim($request->search));
        $page = (int) $request->page;

        $resultCount = 30;

        $offset = ($page - 1) * $resultCount;

        if ($search == '') {
            $dataset = ExpenseAccountView::query()
                ->skip($offset)
                ->take($resultCount)
                ->get();

            $count = ExpenseAccountView::count();
        } else {
            $dataset = ExpenseAccountView::query()
                ->where(function ($query) use ($search) {
                    $query->where(DB::raw('lower(element_group)'), 'like', '%'.$search.'%')
                        ->orWhere(DB::raw('lower(description)'), 'like', '%'.$search.'%');
                })
                ->orderby('element_group', 'asc')
                ->skip($offset)
                ->take($resultCount)
                ->get();

            $count = ExpenseAccountView::query()

                ->where(function ($query) use ($search) {
                    $query->where(DB::raw('lower(element_group)'), 'like', '%'.$search.'%')
                        ->orWhere(DB::raw('lower(description)'), 'like', '%'.$search.'%');
                })->count();

        }

        $endCount = $offset + $resultCount;
        $morePages = $count > $endCount;

        $results = [];
        foreach ($dataset as $item) {
            $results[] = [
                'id' => $item->element_group,
                'text' => $item->element_group.':'.$item->description,
            ];
        }

        $response = [
            'results' => $results,
            'pagination' => ['more' => $morePages],
        ];

        return response()->json($response);
    }
}
