@if($paginator->hasPages())
<nav class="pagination" aria-label="Paginação"><span>Exibindo {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}</span><div>@if($paginator->onFirstPage())<span class="disabled">←</span>@else<a href="{{ $paginator->previousPageUrl() }}">←</a>@endif<span>Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>@if($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}">→</a>@else<span class="disabled">→</span>@endif</div></nav>
@endif
