{{-- Hidden markdown source for clipboard copy. JS reads from data-debug-field spans. --}}
<div x-ref="debugCopySource" class="hidden" aria-hidden="true">
@isset($debugPrompt)<span data-debug-field="prompt">## Prompt
{{ $debugPrompt }}</span>
@endisset
<span data-debug-field="sourceUrl">## Source URL
{{ $debugSourceUrl ?? 'N/A' }}</span>
<span data-debug-field="screenshot">## Screenshot
{{ $debugScreenshot ?? 'None' }}</span>
<span data-debug-field="route">## Route
{{ $debugRouteDisplay ?? 'N/A' }}</span>
<span data-debug-field="handler">## Handler
{{ $debugHandlerFile ?? 'N/A' }}</span>
<span data-debug-field="views">## Views
{{ $debugViewsDisplay ?? 'N/A' }}</span>
<span data-debug-field="authUser">## Auth User
{{ $debugUserDisplay ?? 'Guest' }}</span>
</div>
