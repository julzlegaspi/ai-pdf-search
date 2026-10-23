<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use App\Jobs\ProcessUploadedPdf;
use App\Models\PdfUpload;
use Illuminate\Http\JsonResponse;

class DocumentController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        $documents = DB::table('documents')
            ->select(
                'relative_path',
                'filename',
                DB::raw('COUNT(*) AS total_pages'),
                DB::raw(
                    'COUNT(embedding) AS embedded_pages'
                ),
                DB::raw('MAX(updated_at) AS last_updated')
            )
            ->when($search !== '', function ($query) use ($search) {
                $query->where(
                    'filename',
                    'ILIKE',
                    '%' . addcslashes($search, '%_\\') . '%'
                );
            })
            ->groupBy('relative_path', 'filename')
            ->orderBy('filename')
            ->paginate(20)
            ->withQueryString();

            $uploads = PdfUpload::query()
                ->latest()
                ->paginate(10, ['*'], 'uploads_page');

        return view('documents.index', [
            'documents' => $documents,
            'search' => $search,
            'uploads' => $uploads,
        ]);
    }

    
    public function upload(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pdf' => [
                'required',
                'file',
                'mimes:pdf',
                'max:10240',
            ],
        ]);

        $file = $validated['pdf'];

        // Generate a unique filename to prevent overwriting PDFs.
        $filename = Str::uuid() . '.pdf';

        // Store inside the existing /documents Docker mount.
        $destination = '/documents/' . $filename;

        if (! $file->move('/documents', $filename)) {
            return back()->withErrors([
                'pdf' => 'Unable to save the PDF file.',
            ]);
        }

        $upload = PdfUpload::create([
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $filename,
            'status' => 'queued',
        ]);

        ProcessUploadedPdf::dispatch($upload->id);

        return redirect()
            ->route('documents.index')
            ->with(
                'success',
                'PDF uploaded successfully. Processing has been queued.'
            );
    }

    
    public function statuses(): JsonResponse
    {
        $uploads = PdfUpload::query()
            ->latest()
            ->limit(100)
            ->get([
                'id',
                'status',
                'total_pages',
                'embedded_pages',
                'error_message',
            ]);

        return response()->json([
            'uploads' => $uploads,
        ]);
    }


}
