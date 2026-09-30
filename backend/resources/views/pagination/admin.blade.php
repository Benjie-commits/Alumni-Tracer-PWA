@if ($paginator->hasPages())
    <div class="pager">
        <span class="muted small">
            Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ number_format($paginator->total()) }}
        </span>
        <span>
            <button type="button" class="secondary" wire:click="previousPage" @disabled($paginator->onFirstPage())>← Previous</button>
            <span class="muted small" style="margin:0 8px">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
            <button type="button" class="secondary" wire:click="nextPage" @disabled(! $paginator->hasMorePages())>Next →</button>
        </span>
    </div>
@endif
