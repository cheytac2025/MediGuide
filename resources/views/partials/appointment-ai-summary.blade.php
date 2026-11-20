@php
    $summary = $appointment->relationLoaded('aiSummary')
        ? $appointment->aiSummary
        : $appointment->aiSummary()->first();
    $summaryTrusted = $recordTrusted ?? true;
@endphp

<section class="mg-ai-summary" aria-labelledby="mg-ai-summary-title">
    <div class="mg-ai-summary-head">
        <p class="mg-ai-summary-kicker">AI Summary</p>
        <h2 id="mg-ai-summary-title" class="mg-ai-summary-title">Patient Concern Summary</h2>
    </div>

    @if (! $summaryTrusted)
        <p class="mg-ai-summary-empty">Unavailable</p>
    @elseif (! $summary)
        <p class="mg-ai-summary-empty">No AI summary available.</p>
    @else
        <p class="mg-ai-summary-body">{{ $summary->concern_summary }}</p>

        @php
            $details = $summary->reportedDetailsList();
            $inputs = $summary->originalInputList();
        @endphp

        @if ($details !== [])
            <div class="mg-ai-summary-block">
                <h3 class="mg-ai-summary-subtitle">Reported Symptoms</h3>
                <dl class="mg-ai-summary-list">
                    @foreach ($details as $row)
                        @if (is_array($row) && isset($row['label'], $row['value']))
                            <div>
                                <dt>{{ $row['label'] }}</dt>
                                <dd>{{ $row['value'] }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>
            </div>
        @endif

        <div class="mg-ai-summary-block">
            <h3 class="mg-ai-summary-subtitle">Suggested Department or Specialization</h3>
            <p class="mg-ai-summary-specialty">{{ $summary->suggested_specialization ?: '—' }}</p>
        </div>

        @if ($inputs !== [])
            <div class="mg-ai-summary-block">
                <h3 class="mg-ai-summary-subtitle">Original Patient Input</h3>
                <ul class="mg-ai-summary-quotes">
                    @foreach ($inputs as $input)
                        <li>{{ $input }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="mg-ai-summary-disclaimer">
            AI-generated summary based on patient-reported information. Not a medical diagnosis. Requires professional assessment.
        </p>
    @endif
</section>
