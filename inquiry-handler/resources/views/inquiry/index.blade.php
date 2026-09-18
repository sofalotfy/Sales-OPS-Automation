@extends('layouts.app')

@section('title', 'Inquiry Handler — Test Console')

@section('content')
    <form id="inquiry-form" novalidate>
        <div>
            <label for="message">Your question</label>
            <textarea id="message" name="message" maxlength="4000" required placeholder="e.g. Do you offer annual maintenance contracts for heating boilers?"></textarea>
        </div>

        <div class="row">
            <div>
                <label for="first-name">First name</label>
                <input id="first-name" name="first_name" type="text" maxlength="255" required placeholder="Jane" autocomplete="given-name">
            </div>
            <div>
                <label for="last-name">Last name</label>
                <input id="last-name" name="last_name" type="text" maxlength="255" required placeholder="Doe" autocomplete="family-name">
            </div>
        </div>

        <div>
            <label for="email">Email</label>
            <input id="email" name="email" type="email" maxlength="255" required placeholder="jane@example.com" autocomplete="email">
        </div>

        <div class="row">
            <div>
                <label for="phone-number">Phone number <small>(optional)</small></label>
                <input id="phone-number" name="phone_number" type="tel" maxlength="255" placeholder="+1 555 0132" autocomplete="tel">
            </div>
            <div>
                <label for="company-name">Company name <small>(optional)</small></label>
                <input id="company-name" name="company_name" type="text" maxlength="255" placeholder="Example Corp" autocomplete="organization">
            </div>
        </div>

        <div>
            <label for="country-region">Country/Region <small>(optional)</small></label>
            <input id="country-region" name="country_region" type="text" maxlength="255" placeholder="United Kingdom" autocomplete="country-name">
        </div>

        <button id="submit" type="submit">Send inquiry</button>

        <div class="result" id="result" hidden>
            <div class="tag" id="result-tag"></div>
            <div class="score" id="result-score"></div>
            <div class="reply" id="result-reply"></div>
            <div class="factors" id="factors" hidden>
                <div class="retrieved-title">Factor scores <span id="factor-count"></span></div>
                <ul id="factor-list"></ul>
            </div>
            <div class="dropped" id="dropped" hidden>
                <div class="retrieved-title">Dropped factors</div>
                <ul id="dropped-list"></ul>
            </div>
            <div class="retrieved" id="system-prompt" hidden>
                <div class="retrieved-title">System prompt</div>
                <pre class="prompt-text" id="system-prompt-text"></pre>
            </div>
        </div>
        <div class="error" id="error" hidden></div>
    </form>
@endsection

@push('scripts')
<script>
    const form = document.getElementById('inquiry-form');
    const submit = document.getElementById('submit');
    const result = document.getElementById('result');
    const resultTag = document.getElementById('result-tag');
    const resultScore = document.getElementById('result-score');
    const resultReply = document.getElementById('result-reply');
    const errorBox = document.getElementById('error');
    const factors = document.getElementById('factors');
    const factorList = document.getElementById('factor-list');
    const factorCount = document.getElementById('factor-count');
    const dropped = document.getElementById('dropped');
    const droppedList = document.getElementById('dropped-list');

    function show(data) {
        resultTag.textContent = (data.classification ?? 'unknown').charAt(0).toUpperCase() + (data.classification ?? '').slice(1);
        resultScore.textContent = `Score: ${typeof data.score === 'number' ? data.score : '-'}`;
        resultReply.textContent = data.reply ?? '';
        result.hidden = false;
        errorBox.hidden = true;

        showFactors(data);
        showDropped(data);
        showSystemPrompt(data);
    }

    function showFactors(data) {
        const entries = Object.entries(data.factor_scores ?? {});
        factorList.innerHTML = '';

        for (const [name, score] of entries) {
            const li = document.createElement('li');
            li.className = 'retrieved-item';

            const head = document.createElement('div');
            head.className = 'retrieved-head';
            head.textContent = `${name} · ${score.score} · weight ${score.weight}`;

            const body = document.createElement('p');
            body.className = 'retrieved-text';
            body.textContent = score.reasoning ?? '';

            li.append(head, body);
            factorList.append(li);
        }

        if (entries.length > 0) {
            factorCount.textContent = `(${entries.length})`;
            factors.hidden = false;
        } else {
            factors.hidden = true;
        }
    }

    function showDropped(data) {
        const entries = Array.isArray(data.dropped_factors) ? data.dropped_factors : [];
        droppedList.innerHTML = '';

        for (const d of entries) {
            const li = document.createElement('li');
            li.className = 'retrieved-item';
            const head = document.createElement('div');
            head.className = 'retrieved-head';
            head.textContent = d.name;
            const body = document.createElement('p');
            body.className = 'retrieved-text';
            body.textContent = d.reason ?? '';
            li.append(head, body);
            droppedList.append(li);
        }

        dropped.hidden = entries.length === 0;
    }

    function showSystemPrompt(data) {
        const section = document.getElementById('system-prompt');
        const promptText = document.getElementById('system-prompt-text');
        const prompt = data.context?.system_prompt;

        if (typeof prompt === 'string' && prompt !== '') {
            promptText.textContent = prompt;
            section.hidden = false;
        } else {
            promptText.textContent = 'System prompt was not recorded for this run.';
            section.hidden = false;
        }
    }

    function showError(text) {
        errorBox.textContent = text;
        errorBox.hidden = false;
        result.hidden = true;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        submit.disabled = true;
        try {
            const response = await fetch('/inquiry/triage', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    first_name: document.getElementById('first-name').value.trim(),
                    last_name: document.getElementById('last-name').value.trim(),
                    email: document.getElementById('email').value.trim(),
                    phone_number: document.getElementById('phone-number').value.trim(),
                    company_name: document.getElementById('company-name').value.trim(),
                    country_region: document.getElementById('country-region').value.trim(),
                    message: document.getElementById('message').value.trim(),
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                showError(data.detail ?? 'Something went wrong. Please try again.');
                return;
            }

            show(data);
        } catch {
            showError('Could not reach the service. Please try again shortly.');
        } finally {
            submit.disabled = false;
        }
    });
</script>
@endpush