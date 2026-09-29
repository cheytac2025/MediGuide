@extends('layouts.public')

@section('title', 'Before You Continue')

@section('content')
    <section class="mg-disclaimer-page" aria-labelledby="mgDisclaimerTitle">
        <div class="mg-disclaimer-card">
            <x-queen-mary-logo class="mg-disclaimer-logo" />
            <p class="mg-wordmark">MediGuide</p>
            <p class="mg-wordmark-sub">Your Health. Our Guidance.</p>

            <h1 id="mgDisclaimerTitle" class="mg-disclaimer-title">Before You Continue</h1>

            <div class="mg-disclaimer-copy">
                <p>MediGuide is an AI-assisted virtual front desk designed to help guide you to an appropriate hospital department or medical specialist based on the information you provide.</p>
                <p>MediGuide does not provide medical diagnoses, prescriptions, treatment recommendations, or emergency medical services. Its recommendations are intended for patient navigation only and should not replace consultation with a licensed healthcare professional.</p>
                <p>AI-generated responses may be incomplete or inaccurate. If MediGuide cannot confidently determine the appropriate service, you may be advised to contact authorized hospital personnel.</p>
                <p>If you believe you are experiencing a medical emergency, do not rely on MediGuide. Seek immediate medical assistance or contact the appropriate emergency service.</p>
                <p>By continuing, you acknowledge that you understand the purpose and limitations of MediGuide.</p>
            </div>

            <form method="POST" action="{{ route('ai-disclaimer.acknowledge') }}" class="mg-disclaimer-form" data-mg-disclaimer-form>
                @csrf

                <label class="mg-check mg-disclaimer-check" for="aiDisclaimerAck">
                    <input
                        id="aiDisclaimerAck"
                        type="checkbox"
                        name="acknowledged"
                        value="1"
                        data-mg-disclaimer-ack
                        @checked(old('acknowledged'))
                    >
                    <span>I have read and understand the disclaimer above.</span>
                </label>

                @error('acknowledged')
                    <p class="mg-field-error">{{ $message }}</p>
                @enderror

                <button
                    type="submit"
                    class="mg-auth-submit"
                    data-mg-disclaimer-continue
                    disabled
                >
                    Continue to MediGuide
                </button>
            </form>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        (() => {
            const box = document.querySelector('[data-mg-disclaimer-ack]');
            const button = document.querySelector('[data-mg-disclaimer-continue]');

            if (!box || !button) {
                return;
            }

            const sync = () => {
                button.disabled = !box.checked;
            };

            ['change', 'input', 'click'].forEach((eventName) => {
                box.addEventListener(eventName, sync);
            });
            sync();
        })();
    </script>
@endpush
