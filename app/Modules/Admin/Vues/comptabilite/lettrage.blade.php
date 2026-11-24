@extends('admin::gabarits.application')
@section('titre', 'Lettrage')
@section('topbar_titre', 'Comptabilité · Lettrage')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-link"></i> Lettrage</h1>
        <p>Rapprocher une facture du règlement qui la solde. Ce qui reste non lettré
           est ce qui reste dû.</p>
    </div>
</div>

@if(session('succes'))
    <div style="margin-bottom:16px; padding:12px 16px; background:#ecfdf5; border:1px solid #6ee7b7; border-radius:10px; color:#065f46;">
        <i class="fas fa-check-circle"></i> {{ session('succes') }}
    </div>
@endif
@if(session('erreur'))
    <div style="margin-bottom:16px; padding:12px 16px; background:#fef2f2; border:1px solid #fca5a5; border-radius:10px; color:#991b1b;">
        <i class="fas fa-exclamation-circle"></i> {{ session('erreur') }}
    </div>
@endif

<form method="GET" action="{{ route('admin.comptabilite.lettrage') }}" style="margin-bottom:18px;">
    <div class="card" style="padding:14px 16px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <label class="form-label" style="margin:0; font-weight:700;">Compte à lettrer</label>
        <select name="compte" class="form-control" style="width:auto; min-width:300px;" onchange="this.form.submit()">
            <option value="">Choisir un compte</option>
            @foreach($comptes as $c)
                <option value="{{ $c->numero }}" {{ $compte === $c->numero ? 'selected' : '' }}>
                    {{ $c->numero }} — {{ $c->libelle }}
                </option>
            @endforeach
        </select>
        <noscript><button type="submit" class="btn btn-outline">Afficher</button></noscript>
    </div>
</form>

@if($compte)
    <div class="card" style="padding:14px 18px; margin-bottom:18px; display:flex; align-items:center; gap:12px;">
        <i class="fas fa-hand-holding-dollar" style="color:var(--text-2);"></i>
        <div>
            <strong>Reste dû sur ce compte :
                {{ number_format(abs($resteDu), 2, ',', ' ') }} F
                {{ $resteDu >= 0 ? '(en notre faveur)' : '(à notre charge)' }}</strong>
            <div style="font-size:12.5px; color:var(--text-2); margin-top:2px;">
                Somme des écritures non lettrées. Une écriture lettrée est soldée :
                elle n'a plus rien à faire dans une relance.
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.comptabilite.lettrer') }}">
        @csrf
        {{-- Le compte est indispensable : l'equilibre d'un lettrage se juge de
             son point de vue, une ecriture portant les deux comptes sur la
             meme ligne avec un debit et un credit egaux. --}}
        <input type="hidden" name="compte" value="{{ $compte }}">

        <div class="card" style="padding:0; overflow:hidden;">
            <div style="padding:12px 18px; border-bottom:1px solid var(--border); background:var(--bg3);">
                <strong style="font-size:13.5px;">Écritures ouvertes</strong>
                <div style="font-size:12.5px; color:var(--text-2); margin-top:3px;">
                    Cochez une pièce et son règlement. <strong>Un lettrage équilibre</strong> :
                    lettrer une facture de 100 000 avec un acompte de 40 000 dirait que la créance
                    est éteinte alors qu'il reste 60 000 dus.
                </div>
            </div>

            <div style="overflow-x:auto;">
                <table class="table" style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="text-align:left; color:var(--text-2);">
                            <th style="padding:8px 14px; width:40px;"></th>
                            <th style="padding:8px 14px; width:100px;">Date</th>
                            <th style="padding:8px 14px; width:140px;">Pièce</th>
                            <th style="padding:8px 14px;">Libellé</th>
                            <th style="padding:8px 14px; width:130px; text-align:right;">Débit</th>
                            <th style="padding:8px 14px; width:130px; text-align:right;">Crédit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($ouvertes as $ecriture)
                            <tr style="border-top:1px solid var(--border);">
                                <td style="padding:7px 14px;">
                                    <input type="checkbox" name="ecritures[]" value="{{ $ecriture->id }}"
                                           class="case-lettrage"
                                           data-debit="{{ $ecriture->compte_debit === $compte ? $ecriture->debit : 0 }}"
                                           data-credit="{{ $ecriture->compte_credit === $compte ? $ecriture->credit : 0 }}">
                                </td>
                                <td style="padding:7px 14px; color:var(--text-2);">
                                    {{ \Carbon\Carbon::parse($ecriture->date_ecriture)->format('d/m/Y') }}
                                </td>
                                <td style="padding:7px 14px; font-family:monospace; font-size:11.5px;">
                                    {{ $ecriture->reference_document }}
                                </td>
                                <td style="padding:7px 14px;">{{ $ecriture->libelle }}</td>
                                <td style="padding:7px 14px; text-align:right;">
                                    {{ $ecriture->compte_debit === $compte ? number_format($ecriture->debit, 2, ',', ' ') : '' }}
                                </td>
                                <td style="padding:7px 14px; text-align:right;">
                                    {{ $ecriture->compte_credit === $compte ? number_format($ecriture->credit, 2, ',', ' ') : '' }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" style="text-align:center; padding:26px; color:var(--text-3);">
                                Rien à lettrer : tout est soldé sur ce compte.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($ouvertes->isNotEmpty())
                <div style="padding:14px 18px; border-top:1px solid var(--border); display:flex; align-items:center; gap:14px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-link"></i> Lettrer la sélection
                    </button>
                    <span id="ecart-selection" style="font-size:13px; color:var(--text-2);">
                        Rien de sélectionné.
                    </span>
                </div>
            @endif
        </div>
    </form>

    <script>
    // L'ecart s'affiche a la selection : c'est le moment ou l'on peut encore
    // ajuster, pas apres le refus du serveur.
    (function () {
        const cases = document.querySelectorAll('.case-lettrage');
        const message = document.getElementById('ecart-selection');
        if (!cases.length || !message) return;

        function recalculer() {
            let debit = 0, credit = 0, nombre = 0;

            cases.forEach(c => {
                if (!c.checked) return;
                nombre++;
                debit  += parseFloat(c.dataset.debit)  || 0;
                credit += parseFloat(c.dataset.credit) || 0;
            });

            if (nombre === 0) { message.textContent = 'Rien de sélectionné.'; message.style.color = ''; return; }

            const ecart = Math.round((debit - credit) * 100) / 100;

            if (nombre < 2) {
                message.textContent = 'Un lettrage rapproche au moins deux écritures.';
                message.style.color = 'var(--text-2)';
            } else if (Math.abs(ecart) < 0.01) {
                message.textContent = `${nombre} écritures, équilibrées : le lettrage est possible.`;
                message.style.color = 'var(--success)';
            } else {
                message.textContent = `${nombre} écritures — il reste ${Math.abs(ecart).toLocaleString('fr-FR', {minimumFractionDigits: 2})} F d'écart.`;
                message.style.color = 'var(--danger)';
            }
        }

        cases.forEach(c => c.addEventListener('change', recalculer));
    })();
    </script>
@endif

@if($lettrages->isNotEmpty())
    <div class="card" style="padding:0; overflow:hidden; margin-top:22px;">
        <div style="padding:12px 18px; border-bottom:1px solid var(--border); background:var(--bg3);">
            <strong style="font-size:13.5px;">Derniers lettrages</strong>
        </div>
        <table class="table" style="width:100%; border-collapse:collapse; font-size:13px;">
            <tbody>
                @foreach($lettrages as $l)
                    <tr style="border-top:1px solid var(--border);">
                        <td style="padding:8px 18px; width:60px;">
                            <span class="badge" style="background:#ecfdf5; color:#047857; padding:2px 8px;
                                         border-radius:5px; font-weight:700;">{{ $l->code }}</span>
                        </td>
                        <td style="padding:8px 14px; color:var(--text-2);">
                            {{ $l->date_lettrage?->format('d/m/Y') }}
                        </td>
                        <td style="padding:8px 14px;">{{ $l->ecritures_count }} écriture(s)</td>
                        <td style="padding:8px 18px; text-align:right;">
                            <form method="POST" action="{{ route('admin.comptabilite.delettrer', $l) }}" style="display:inline;">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-outline" style="padding:4px 10px; font-size:12px;">
                                    <i class="fas fa-link-slash"></i> Défaire
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
