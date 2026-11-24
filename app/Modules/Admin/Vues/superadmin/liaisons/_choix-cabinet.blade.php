{{--
    Le cabinet Comptaflow qui reçoit le dossier (24/11/2026) : l'espace du
    cabinet, ou un membre précis (il est alors aussi rangé dans le cabinet).
    Liste avec recherche. Variables : $cabinetsComptaflow, $idChoix,
    $facultatif (bool).
--}}
@php $listeCabinets = $cabinetsComptaflow['cabinets'] ?? []; @endphp
<div data-choix-cabinet>
    <label class="form-label" for="{{ $idChoix }}">Cabinet Comptaflow {!! $facultatif ? '<span style="font-weight:400;color:var(--text-3);">(facultatif)</span>' : '<span style="color:var(--danger)">*</span>' !!}</label>
    @if(empty($cabinetsComptaflow['disponible']))
        <div style="font-size:12px; color:#92400e; background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:8px 10px;">
            <i class="fas fa-triangle-exclamation"></i> {{ $cabinetsComptaflow['message'] ?? 'Liste des cabinets indisponible.' }}
        </div>
    @elseif(empty($listeCabinets))
        <div style="font-size:12px; color:var(--text-3);">Aucun cabinet actif dans Comptaflow.</div>
    @else
        <input type="search" class="form-control" placeholder="Rechercher un cabinet, un membre ou une adresse…" autocomplete="off"
               data-recherche-cabinet style="margin-bottom:6px;">
        <select name="cabinet" id="{{ $idChoix }}" class="form-control" size="7" {{ $facultatif ? '' : 'required' }} style="height:auto;">
            @if($facultatif)
                <option value="" selected>Aucun cabinet</option>
            @endif
            @foreach($listeCabinets as $cab)
                <optgroup label="{{ $cab['nom'] }}{{ $cab['code'] ? ' · ' . $cab['code'] : '' }}">
                    <option value="c:{{ $cab['id'] }}" data-texte="{{ mb_strtolower($cab['nom'] . ' ' . $cab['code']) }}">{{ $cab['nom'] }} · espace du cabinet</option>
                    @foreach($cab['membres'] as $m)
                        <option value="u:{{ $cab['id'] }}:{{ $m['id'] }}" data-texte="{{ mb_strtolower($cab['nom'] . ' ' . $m['nom'] . ' ' . $m['email']) }}">
                            &nbsp;&nbsp;{{ $m['nom'] }}{{ $m['role'] === 'gerant' ? ' (gérant)' : '' }}{{ $m['email'] ? ' · ' . $m['email'] : '' }}
                        </option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
        <small style="color:var(--text-3); font-size:11px; line-height:1.5; display:block; margin-top:4px;">
            « Espace du cabinet » : le dossier entre dans le portefeuille du cabinet, son gérant le voit.
            Un membre : le dossier lui est confié, et rangé aussi dans son cabinet.
        </small>
    @endif
</div>
@once
<script>
document.addEventListener('input', function (e) {
    if (!e.target.matches('[data-recherche-cabinet]')) return;
    var q = e.target.value.trim().toLowerCase();
    var select = e.target.closest('[data-choix-cabinet]').querySelector('select');
    select.querySelectorAll('optgroup').forEach(function (g) {
        var groupe = g.label.toLowerCase().indexOf(q) !== -1;
        var visibles = 0;
        g.querySelectorAll('option').forEach(function (o) {
            var ok = !q || groupe || (o.dataset.texte || '').indexOf(q) !== -1;
            o.hidden = !ok; if (ok) visibles++;
        });
        g.hidden = visibles === 0;
    });
});
</script>
@endonce
