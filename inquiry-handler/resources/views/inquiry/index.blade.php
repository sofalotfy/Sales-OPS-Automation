@extends('layouts.app')

@section('document_title', 'Talk to us — Robusta Studio')

@section('title', 'Tell us what you need')

@section('badge', 'Inquiry Handler — Test Console')

@section('subtitle', 'Share a few details about your project and we will route it to the team that fits best.')

@section('content')
    <form id="inquiry-form" novalidate>
        <div>
            <label for="message">What can we help you with?</label>
            <textarea id="message" name="message" maxlength="4000" required placeholder="e.g. We run a Shopify Plus storefront and need a B2B ordering portal for roughly 40 SKUs. Looking to start next quarter."></textarea>
            <p class="hint">A couple of sentences is plenty &mdash; concrete beats polished.</p>
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
            <label for="email">Email <small>(optional)</small></label>
            <input id="email" name="email" type="email" maxlength="255" placeholder="jane@example.com" autocomplete="email">
        </div>

        <div class="row">
            <div>
                <label for="phone-number">Phone number <small>(optional)</small></label>
                <input id="phone-number" name="phone_number" type="tel" maxlength="255" placeholder="+1 555 0132" autocomplete="tel">
            </div>
            <div>
                <label for="company-name">Company name</label>
                <input id="company-name" name="company_name" type="text" maxlength="255" required placeholder="Example Corp" autocomplete="organization">
            </div>
        </div>

        <div>
            <label for="country-region">Country/Region <small>(optional)</small></label>
            <input id="country-region" name="country_region" type="text" maxlength="255" placeholder="United Kingdom" autocomplete="country-name">
        </div>

        <button class="submit" id="submit" type="submit">Send inquiry</button>

        <div class="status" id="status" hidden></div>

        <div class="examples">
            <p class="examples-title">Or start from a sample request:</p>
            <div class="chips" id="examples"></div>
        </div>
    </form>

    {{-- Filled in by the poller once POST /inquiry/triage accepts the run. It is
         split in two on purpose: the reply block is what a visitor is shown, and
         the collapsed panel underneath is the operator's view of the same run. --}}
    <div id="run-panel" hidden>
        <ol class="stages" id="stages"></ol>

        <div class="reply" id="reply" hidden></div>

        <div class="status error" id="run-error" hidden></div>

        <details class="run" id="run-details" hidden>
            <summary>Run details <span class="verdict" id="verdict" hidden></span></summary>

            <div class="kv" id="run-kv"></div>

            <div class="factor" id="factor-scores" hidden>
                <h3>Factor scores</h3>
                <table>
                    <thead>
                        <tr><th>Factor</th><th>Score</th><th>Weight</th><th>Reasoning</th></tr>
                    </thead>
                    <tbody id="factor-rows"></tbody>
                </table>
            </div>

            <div class="factor" id="dropped-factors" hidden>
                <h3>Dropped factors</h3>
                <p id="dropped-list"></p>
            </div>

            <div class="factor" id="run-reasoning" hidden>
                <h3>Reasoning</h3>
                <p id="reasoning-text"></p>
            </div>

            <pre id="raw-json"></pre>
        </details>

        <button class="link" id="reset" type="button">Send another inquiry</button>
    </div>
@endsection

@push('scripts')
<script>
    // The inquiry surface is share-key authenticated (`crm.key` middleware).
    // This console is a local manual-testing aid served by the same app, so it
    // presents the key from server-side config instead of making the tester
    // paste it. It is never rendered for an unauthenticated caller of the API.
    const crmKey = @json((string) config('services.crm_key'));
    const bookingUrl = @json((string) config('services.booking_url'));

    // The stages ProcessTriageJob walks, in order, as the run row advances.
    const STAGES = [
        { key: 'queued', label: 'Received' },
        { key: 'processing', label: 'Reading your request' },
        { key: 'researching', label: 'Researching your company' },
        { key: 'scope_check', label: 'Checking it is something we do' },
        { key: 'scoring', label: 'Scoring the fit' },
    ];
    const TERMINAL = ['succeeded', 'failed'];
    const POLL_INTERVAL_MS = 2000;
    // Runs can spend minutes in web research, so the ceiling is generous; past
    // it the console stops polling and points at the dashboard rather than
    // spinning forever on a run the worker may still be holding.
    const MAX_POLLS = 150;

    const EXAMPLES = [
        {
            label: 'B2B commerce build',
            message: 'We run a Shopify Plus storefront for a distributor and need a B2B ordering portal with account pricing for roughly 400 SKUs. Currently we handle purchase orders over email and it is not scaling. Target launch is next quarter.',
            company_name: 'Northwind Distribution',
            country_region: 'United Kingdom',
        },
        {
            label: 'AI search on our catalogue',
            message: 'Our customers cannot find products on a 30,000 SKU catalogue. Looking for smart search and recommendations, ideally something we can bolt onto the existing Magento build rather than rebuild it.',
            company_name: 'Alpine Retail Group',
            country_region: 'Germany',
        },
        {
            label: 'Out of scope',
            message: 'We need someone to run weekly payroll for our 12 staff in the UK and handle our VAT filings.',
            company_name: 'Hollowbrook Ltd',
            country_region: 'United Kingdom',
        },
    ];

    const form = document.getElementById('inquiry-form');
    const submit = document.getElementById('submit');
    const status = document.getElementById('status');
    const panel = document.getElementById('run-panel');
    const stagesEl = document.getElementById('stages');
    const replyEl = document.getElementById('reply');
    const runErrorEl = document.getElementById('run-error');
    const detailsEl = document.getElementById('run-details');
    const verdictEl = document.getElementById('verdict');
    const kvEl = document.getElementById('run-kv');
    const factorScoresEl = document.getElementById('factor-scores');
    const factorRowsEl = document.getElementById('factor-rows');
    const droppedEl = document.getElementById('dropped-factors');
    const droppedListEl = document.getElementById('dropped-list');
    const reasoningEl = document.getElementById('run-reasoning');
    const reasoningTextEl = document.getElementById('reasoning-text');
    const rawEl = document.getElementById('raw-json');
    const resetEl = document.getElementById('reset');

    let pollTimer = null;
    // The furthest stage the poll has observed. Needed because a failed run's
    // terminal status does not name the stage it died in.
    let lastStage = 'queued';

    const field = (id) => document.getElementById(id).value.trim();

    function showStatus(text, isError) {
        status.textContent = text;
        status.classList.toggle('error', isError);
        status.hidden = false;
    }

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    // Render the pipeline as a visitor-facing checklist so a slow run reads as
    // "we are on it" rather than a spinner that never resolves.
    //
    // `done` marks every stage complete (the run succeeded). `failed` marks the
    // stage the run stopped at — the terminal status alone does not name it, so
    // the caller passes the last stage the poll observed. Otherwise `active` is
    // the stage currently in progress and the ones after it are still pending.
    function renderStages({ done = false, failed = false, active = null } = {}) {
        stagesEl.replaceChildren();

        const reached = done
            ? STAGES.length
            : Math.max(STAGES.findIndex((stage) => stage.key === active), 0);

        STAGES.forEach((stage, index) => {
            const item = el('li');
            item.append(el('span', 'dot'));

            if (failed && index === reached) {
                item.classList.add('failed');
            } else if (index < reached) {
                item.classList.add('done');
            } else {
                item.classList.add('active');
            }

            item.append(el('span', null, stage.label));
            stagesEl.append(item);
        });
    }

    function showReply(result) {
        const reply = String(result?.reply ?? '').trim();

        if (reply === '') {
            return;
        }

        replyEl.replaceChildren(el('p', null, reply));

        // The high-classification reply carries the booking link inline. Promote
        // it to a real button so it behaves like the one a client would get.
        if (bookingUrl !== '' && reply.includes(bookingUrl)) {
            const button = el('a', 'cta', 'Book a call');
            button.href = bookingUrl;
            button.target = '_blank';
            button.rel = 'noopener';
            replyEl.append(button);
        }

        replyEl.hidden = false;
    }

    function addKv(label, value) {
        const wrapper = el('div');
        wrapper.append(document.createTextNode(`${label}: `), el('b', null, value));
        kvEl.append(wrapper);
    }

    function renderDetails(payload, result, elapsedSeconds) {
        detailsEl.hidden = false;
        kvEl.replaceChildren();

        addKv('Run', `#${payload.inquiry_id}`);
        addKv('Status', String(payload.status ?? 'unknown'));
        addKv('Elapsed', `${elapsedSeconds}s`);

        if (result) {
            addKv('Score', Number(result.score ?? 0).toFixed(2));

            if (result.classification) {
                verdictEl.textContent = result.classification;
                verdictEl.className = `verdict ${result.classification}`;
                verdictEl.hidden = false;
            }
        }

        const factors = result?.factor_scores ?? {};
        if (Object.keys(factors).length > 0) {
            factorRowsEl.replaceChildren();

            Object.entries(factors).forEach(([name, factor]) => {
                const row = el('tr');
                row.append(el('td', null, name));
                row.append(el('td', 'num', String(factor?.score ?? '—')));
                row.append(el('td', 'num', String(factor?.weight ?? '—')));
                row.append(el('td', null, String(factor?.reasoning ?? '')));
                factorRowsEl.append(row);
            });

            factorScoresEl.hidden = false;
        }

        const dropped = result?.dropped_factors ?? [];
        if (dropped.length > 0) {
            droppedListEl.textContent = dropped
                .map((item) => `${item?.factor ?? 'unknown'} — ${item?.reason ?? 'no reason given'}`)
                .join(' · ');
            droppedEl.hidden = false;
        }

        if (result?.reasoning) {
            reasoningTextEl.textContent = result.reasoning;
            reasoningEl.hidden = false;
        }

        rawEl.textContent = JSON.stringify(payload, null, 2);
    }

    function renderTerminal(payload, startedAt) {
        const result = payload.result ?? null;
        const elapsed = Math.max(1, Math.round((Date.now() - startedAt) / 1000));
        const failed = payload.status === 'failed';

        renderStages(failed ? { failed: true, active: lastStage } : { done: true });

        if (failed) {
            runErrorEl.textContent = payload.error
                ? `This run could not be completed: ${payload.error}`
                : 'This run could not be completed.';
            runErrorEl.hidden = false;
            renderDetails(payload, null, elapsed);
            return;
        }

        if (!result) {
            runErrorEl.textContent = 'The run finished without producing a classification.';
            runErrorEl.hidden = false;
            renderDetails(payload, null, elapsed);
            return;
        }

        showReply(result);
        renderDetails(payload, result, elapsed);
    }

    function stopPolling() {
        if (pollTimer !== null) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    async function poll(inquiryId, startedAt, remaining) {
        if (remaining <= 0) {
            showReply({ reply: 'Your request is still with our team. We will come back to you shortly.' });
            runErrorEl.textContent = `Run #${inquiryId} is still in the pipeline — open the dashboard to follow it.`;
            runErrorEl.classList.remove('error');
            runErrorEl.hidden = false;
            return;
        }

        try {
            const response = await fetch(`/inquiry/${inquiryId}`, {
                headers: { 'Accept': 'application/json', 'X-CRM-Key': crmKey },
            });
            const data = await response.json();

            if (!response.ok) {
                runErrorEl.textContent = data.detail ?? 'Lost contact with the service. Please try again shortly.';
                runErrorEl.hidden = false;
                return;
            }

            if (TERMINAL.includes(data.status)) {
                renderTerminal(data, startedAt);
                return;
            }

            lastStage = data.status;
            renderStages({ active: lastStage });
            rawEl.textContent = JSON.stringify(data, null, 2);
        } catch {
            runErrorEl.textContent = 'Lost contact with the service. Please try again shortly.';
            runErrorEl.hidden = false;
            return;
        }

        pollTimer = setTimeout(() => poll(inquiryId, startedAt, remaining - 1), POLL_INTERVAL_MS);
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        stopPolling();

        submit.disabled = true;
        status.hidden = true;
        runErrorEl.classList.add('error');
        panel.hidden = true;
        lastStage = 'queued';

        const startedAt = Date.now();

        try {
            const response = await fetch('/inquiry/triage', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CRM-Key': crmKey,
                },
                body: JSON.stringify({
                    first_name: field('first-name'),
                    last_name: field('last-name'),
                    email: field('email'),
                    phone_number: field('phone-number'),
                    company_name: field('company-name'),
                    country_region: field('country-region'),
                    message: field('message'),
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                showStatus(data.detail ?? 'Something went wrong. Please try again.', true);
                return;
            }

            // 202 (freshly queued) and 409 (already accepted for this lead) both
            // hand back a run to follow, so treat them the same from here.
            form.hidden = true;
            panel.hidden = false;
            renderStages({ active: lastStage });
            await poll(Number(data.inquiry_id), startedAt, MAX_POLLS);
        } catch {
            showStatus('Could not reach the service. Please try again shortly.', true);
        } finally {
            submit.disabled = false;
        }
    });

    resetEl.addEventListener('click', () => {
        stopPolling();
        form.reset();
        form.hidden = false;
        panel.hidden = true;
        status.hidden = true;
        runErrorEl.hidden = true;
        replyEl.replaceChildren();
        replyEl.hidden = true;
        stagesEl.replaceChildren();
        detailsEl.hidden = true;
        verdictEl.hidden = true;
        factorScoresEl.hidden = true;
        droppedEl.hidden = true;
        reasoningEl.hidden = true;
        rawEl.textContent = '';
    });

    EXAMPLES.forEach((example) => {
        const chip = el('button', 'chip', example.label);
        chip.type = 'button';
        chip.addEventListener('click', () => {
            document.getElementById('message').value = example.message;
            document.getElementById('company-name').value = example.company_name;
            document.getElementById('country-region').value = example.country_region;
            document.getElementById('message').focus();
        });
        document.getElementById('examples').append(chip);
    });
</script>
@endpush
