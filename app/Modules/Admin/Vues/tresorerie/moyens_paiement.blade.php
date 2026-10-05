@extends('admin::gabarits.application')
@section('titre', 'Moyens de paiement')
@section('topbar_titre', 'Trésorerie — Moyens de paiement')

{{--
    La même table que les codes journaux, vue sans sa colonne de compte.

    Le lot 39 a masqué les codes journaux aux entreprises qui ne tiennent pas
    leur comptabilité dans Selflow — et leur a fermé du même coup le seul écran
    où déclarer leur banque ou leur mobile money. Cet écran est la porte qui
    reste : mêmes lignes, mêmes données, sans rien demander de comptable.
--}}

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-credit-card"></i> Moyens de paiement</h1>
        <p>Vos banques, votre caisse et vos comptes de monnaie électronique</p>
    </div>
    <div style="display:flex; gap:12px; align-items:center;">
        <button type="button" class="btn btn-primary" data-modal-open="modalNouveauMoyen">
            <i class="fas fa-plus-circle"></i> Nouveau moyen de paiement
        </button>
    </div>
</div>

@if(session('succes'))
<div class="card" style="padding:12px 16px;margin-bottom:16px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-size:13px;">
    <i class="fas fa-circle-check"></i> {{ session('succes') }}
</div>
@endif

@if($errors->any())
<div class="card" style="padding:12px 16px;margin-bottom:16px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-size:13px;">
    <i class="fas fa-triangle-exclamation"></i> {{ $errors->first() }}
</div>
@endif

<div class="card">
    <div class="table-wrap">
        @if($moyens->isEmpty())
        <div style="padding:48px; text-align:center; color:var(--text-3);">
            <i class="fas fa-credit-card" style="font-size:48px; display:block; margin-bottom:12px; opacity:.2;"></i>
            Aucun moyen de paiement enregistré.
        </div>
        @else
        <table>
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Intitulé</th>
                    <th>Code</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($moyens as $moyen)
                <tr>
                    <td>
                        <span style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:20px;
                                     font-size:11px;font-weight:700;
                                     background:{{ $moyen->type === 'Caisse' ? '#fffbeb' : '#eff6ff' }};
                                     color:{{ $moyen->type === 'Caisse' ? '#92400e' : '#1e40af' }};">
                            <i class="fas {{ $moyen->type === 'Caisse' ? 'fa-cash-register' : 'fa-building-columns' }}"></i>
                            {{ $moyen->type }}
                        </span>
                    </td>
                    <td style="font-weight:600;">{{ $moyen->intitule }}</td>
                    <td><code>{{ $moyen->code }}</code></td>
                    <td>
                        <div style="display:flex;gap:8px;">
                            <button type="button" class="btn btn-sm btn-outline"
                                    data-modal-open="modalModifier{{ $moyen->id }}">
                                <i class="fas fa-pen"></i> Renommer
                            </button>

                            {{-- La caisse ne se supprime pas : tout
                                 encaissement en espèces s'y range, et les
                                 règlements déjà passés la portent. --}}
                            @if($moyen->type !== 'Caisse')
                            <form method="POST"
                                  action="{{ route('admin.tresorerie.supprimer_moyen_paiement', $moyen) }}"
                                  style="margin:0;"
                                  onsubmit="return confirm('Supprimer « {{ $moyen->intitule }} » ? Les règlements déjà enregistrés ne changent pas.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline" style="color:#b91c1c;">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
</div>

{{-- ── Renommer ─────────────────────────────────────────────────────
     Le code ne se change pas : il est la clé sous laquelle les règlements
     déjà passés sont rangés. Le type non plus. --}}
@foreach($moyens as $moyen)
<div class="modal-overlay" id="modalModifier{{ $moyen->id }}">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-pen"></i> Renommer « {{ $moyen->code }} »</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="POST" action="{{ route('admin.tresorerie.modifier_moyen_paiement', $moyen) }}">
            @csrf
            @method('PUT')
                <div class="form-group">
                    <label class="form-label">Intitulé *</label>
                    <input type="text" name="intitule" class="form-control"
                           value="{{ $moyen->intitule }}" required maxlength="255">
                </div>
                <div style="font-size:12px;color:var(--text-3);line-height:1.6;">
                    Le code <code>{{ $moyen->code }}</code> et le type <b>{{ $moyen->type }}</b> ne
                    changent pas : vos règlements déjà enregistrés y sont rattachés.
                </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
                <button type="button" class="btn btn-outline" data-modal-close>Annuler</button>
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>
@endforeach

{{-- ── Nouveau moyen de paiement ───────────────────────────────────── --}}
<div class="modal-overlay" id="modalNouveauMoyen">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle"></i> Nouveau moyen de paiement</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form method="POST" action="{{ route('admin.tresorerie.creer_moyen_paiement') }}">
            @csrf
                <div class="form-group">
                    <label class="form-label">Type *</label>
                    <select name="type" class="form-control" required>
                        <option value="Banque">Banque — compte bancaire ou monnaie électronique</option>
                        <option value="Caisse">Caisse — espèces</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Intitulé *</label>
                    <input type="text" name="intitule" class="form-control" required maxlength="255"
                           placeholder="Ex : NSIA Banque, Orange Money, Caisse du magasin">
                </div>

                <div class="form-group">
                    <label class="form-label">Code *</label>
                    <input type="text" name="code" class="form-control" required maxlength="50"
                           placeholder="Ex : NSIA, OM, CAI"
                           style="text-transform:uppercase;">
                    <div style="font-size:11.5px;color:var(--text-3);margin-top:6px;line-height:1.5;">
                        Un raccourci court, qui vous servira à reconnaître ce moyen dans vos écrans.
                        Il ne se change plus ensuite.
                    </div>
                </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
                <button type="button" class="btn btn-outline" data-modal-close>Annuler</button>
                <button type="submit" class="btn btn-primary">Créer</button>
            </div>
        </form>
    </div>
</div>
@endsection
