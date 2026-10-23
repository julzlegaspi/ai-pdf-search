<?php

namespace App\Http\Controllers;

use App\Services\DocumentAnswerService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DocumentSearchController extends Controller
{
    public function index(): View
    {
        return view('document-search');
    }

    public function ask(
        Request $request,
        DocumentAnswerService $service
    ) {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
        ]);

        $result = $service->ask($validated['question']);

        return view('document-search', [
            'question' => $validated['question'],
            'answer' => $result['answer'],
            'sources' => $result['sources'],
        ]);
    }
}
