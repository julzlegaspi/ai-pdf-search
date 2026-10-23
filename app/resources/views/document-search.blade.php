
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI PDF Search</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #f4f7fb;
            color: #172033;
            font-family: Arial, sans-serif;
        }

        .container {
            max-width: 900px;
            margin: 60px auto;
            padding: 0 20px;
        }

        header { margin-bottom: 32px; }

        h1 {
            margin-bottom: 8px;
            font-size: 32px;
        }

        .muted { color: #65718a; }

        .panel {
            background: white;
            padding: 24px;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            margin-bottom: 20px;
        }

        textarea {
            width: 100%;
            min-height: 110px;
            padding: 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font: inherit;
            resize: vertical;
        }

        button {
            margin-top: 12px;
            padding: 12px 22px;
            background: #2457d6;
            color: white;
            border: 0;
            border-radius: 8px;
            font-size: 15px;
            cursor: pointer;
        }

        button:hover { background: #1945b5; }

        .answer {
            white-space: pre-wrap;
            line-height: 1.7;
        }

        .source {
            padding: 14px 0;
            border-top: 1px solid #e2e8f0;
        }

        .source:first-child { border-top: 0; }

        .error {
            color: #b42318;
            margin-top: 8px;
        }

        @media (max-width: 600px) {
            .container { margin-top: 28px; }
            h1 { font-size: 26px; }
            .panel { padding: 18px; }
        }
    </style>
</head>
<body>
    <main class="container">
        <header>
            <p>
                <a href="{{ route('documents.index') }}">
                    View Indexed Documents →
                </a>
            </p>
            <h1>AI PDF Search</h1>
            <p class="muted">
                Ask questions and get answers from your PDF documents.
            </p>
        </header>

        <section class="panel">
            <form method="POST" action="{{ route('documents.ask') }}">
                @csrf

                <label for="question">
                    <strong>Your question</strong>
                </label>

                <p class="muted">
                    Ask a question about the documents you have indexed.
                </p>

                <textarea
                    id="question"
                    name="question"
                    maxlength="2000"
                    required
                    placeholder="e.g. What are the payment terms?"
                >{{ old('question', $question ?? '') }}</textarea>

                @error('question')
                    <p class="error">{{ $message }}</p>
                @enderror

                <button type="submit">Ask AI</button>
            </form>
        </section>

        @isset($answer)
            <section class="panel">
                <h2>Answer</h2>
                <div class="answer">{{ $answer }}</div>
            </section>

            <section class="panel">
                <h2>Sources</h2>

                @forelse ($sources as $source)
                    <div class="source">
                        <strong>{{ $source['label'] }}</strong>
                        <div>{{ $source['filename'] }}</div>
                        <div class="muted">
                            Page {{ $source['page'] }}
                        </div>
                        <div class="muted">
                            {{ $source['path'] }}
                        </div>
                    </div>
                @empty
                    <p class="muted">
                        No matching PDF sources were found.
                    </p>
                @endforelse
            </section>
        @endisset
    </main>
</body>
</html>
