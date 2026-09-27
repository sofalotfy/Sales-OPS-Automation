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

        <div class="status" id="status" hidden></div>
    </form>
@endsection

@push('scripts')
<script>
    // The inquiry surface is share-key authenticated (`crm.key` middleware).
    // This console is a local manual-testing aid served by the same app, so it
    // presents the key from server-side config instead of making the tester
    // paste it. It is never rendered for an unauthenticated caller of the API.
    const crmKey = @json((string) config('services.crm_key'));

    const form = document.getElementById('inquiry-form');
    const submit = document.getElementById('submit');
    const status = document.getElementById('status');

    function showStatus(text, isError) {
        status.textContent = text;
        status.classList.toggle('error', isError);
        status.hidden = false;
    }

    // POST /inquiry/triage hands the run to the Redis queue and answers 202, so
    // this console only enqueues. Nothing here waits on or renders the
    // classification; outcomes are inspected on the dashboard instead.
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        submit.disabled = true;
        status.hidden = true;

        try {
            const response = await fetch('/inquiry/triage', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CRM-Key': crmKey,
                },
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
                showStatus(data.detail ?? 'Something went wrong. Please try again.', true);
                return;
            }

            showStatus(`Queued · #${String(data.inquiry_id ?? '').slice(0, 8)}`, false);
        } catch {
            showStatus('Could not reach the service. Please try again shortly.', true);
        } finally {
            submit.disabled = false;
        }
    });
</script>
@endpush
