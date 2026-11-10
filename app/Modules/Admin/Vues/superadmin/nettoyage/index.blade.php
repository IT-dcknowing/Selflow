@extends('admin::gabarits.application')

@section('titre', 'Nettoyage des données')
@section('topbar_titre', 'SuperAdmin — Nettoyage des données')

@section('contenu')
<div style="max-width:1200px; margin:0 auto;">
    <div class="card" style="padding:16px 20px; margin-bottom:16px; line-height:1.7;">
        <strong><i class="fas fa-triangle-exclamation" style="color:#d97706;"></i> Opérations de maintenance par entreprise</strong>
        <p style="margin:8px 0 0; color:var(--text-2); font-size:13px;">
            Choisissez une entreprise, une catégorie et le statut des éléments à traiter.
            Les ventes, avoirs et achats peuvent être archivés, restaurés ou purgés.
            Les points de vente peuvent être fermés, rouverts ou supprimés uniquement lorsqu'ils sont vides.
        </p>
        <p style="margin:6px 0 0; color:var(--text-2); font-size:12px;">
            Une purge conserve les pièces certifiées, les écritures comptables, les règlements, les mouvements de stock et les documents liés.
            Pour supprimer une pièce protégée, il faut d'abord traiter correctement ses dépendances dans les modules concernés.
        </p>
    </div>

    @if(session('succes'))
        <div style="background:#ecfdf5; border:1px solid #6ee7b7; color:#065f46; border-radius:10px; padding:12px 16px; margin-bottom:14px; font-weight:600;">{{ session('succes') }}</div>
    @endif
    @if(session('erreur'))
        <div style="background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; border-radius:10px; padding:12px 16px; margin-bottom:14px; font-weight:600;">{{ session('erreur') }}</div>
    @endif
    @if($errors->any())
        <div style="background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; border-radius:10px; padding:12px 16px; margin-bottom:14px;">
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    <div class="card" style="padding:20px; margin-bottom:18px;">
        <form method="POST" action="{{ route('superadmin.nettoyage.executer') }}" id="formNettoyage">
            @csrf
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:14px;">
                <div>
                    <label class="form-label" for="entrepriseNettoyage">Entreprise</label>
                    <select class="form-control" name="entreprise_id" id="entrepriseNettoyage" required>
                        <option value="">— Sélectionner —</option>
                        @foreach($entreprises as $entreprise)
                            <option value="{{ $entreprise->id }}" data-nom="{{ $entreprise->nom }}" {{ old('entreprise_id') == $entreprise->id ? 'selected' : '' }}>{{ $entreprise->nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label" for="cibleNettoyage">Données concernées</label>
                    <select class="form-control" name="cible" id="cibleNettoyage" required>
                        <option value="ventes">Ventes / factures</option>
                        <option value="avoirs">Factures d'avoir</option>
                        <option value="achats">Achats de produits</option>
                        <option value="points_de_vente">Points de vente</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="etatNettoyage">État actuel</label>
                    <select class="form-control" name="etat" id="etatNettoyage" required>
                        <option value="actifs">Non archivés / ouverts</option>
                        <option value="archives">Archivés</option>
                        <option value="tous">Tous</option>
                        <option value="ouverts">Ouverts</option>
                        <option value="fermes">Fermés</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="actionNettoyage">Opération</label>
                    <select class="form-control" name="action" id="actionNettoyage" required>
                        <option value="archiver">Archiver</option>
                        <option value="restaurer">Désarchiver</option>
                        <option value="purger">Supprimer définitivement</option>
                        <option value="fermer">Fermer les points de vente</option>
                        <option value="ouvrir">Rouvrir les points de vente</option>
                        <option value="supprimer">Supprimer les points de vente vides</option>
                    </select>
                </div>
            </div>

            <div style="margin-top:16px; padding:14px; border:1px solid var(--border); border-radius:10px;">
                <label class="form-label" for="confirmationNettoyage">Pour confirmer, retapez exactement le nom de l'entreprise sélectionnée</label>
                <input class="form-control" type="text" name="confirmation" id="confirmationNettoyage" autocomplete="off" required>
                <label style="display:flex; align-items:flex-start; gap:9px; margin-top:12px; font-size:13px;">
                    <input type="checkbox" name="confirmer" value="1" required style="margin-top:3px;">
                    <span>Je confirme avoir sélectionné la bonne entreprise et les bonnes données. Je comprends qu'une purge est définitive.</span>
                </label>
            </div>

            <div style="display:flex; justify-content:flex-end; margin-top:16px;">
                <button type="submit" class="btn btn-primary" id="boutonNettoyage">
                    <i class="fas fa-broom"></i> Exécuter l'opération
                </button>
            </div>
        </form>
    </div>

    <div class="card" style="padding:0; overflow-x:auto;">
        <div style="padding:16px 18px; border-bottom:1px solid var(--border); font-weight:700;">État des données par entreprise</div>
        <table class="table" style="width:100%; min-width:850px;">
            <thead>
                <tr>
                    <th>Entreprise</th><th>Ventes actives</th><th>Ventes archivées</th>
                    <th>Avoirs actifs</th><th>Avoirs archivés</th><th>Achats actifs</th><th>Achats archivés</th><th>Points de vente</th>
                </tr>
            </thead>
            <tbody>
                @forelse($entreprises as $entreprise)
                    @php($s = $stats[$entreprise->id])
                    <tr>
                        <td><strong>{{ $entreprise->nom }}</strong></td>
                        <td>{{ number_format($s['ventes_actives'], 0, ',', ' ') }}</td>
                        <td>{{ number_format($s['ventes_archivees'], 0, ',', ' ') }}</td>
                        <td>{{ number_format($s['avoirs_actifs'], 0, ',', ' ') }}</td>
                        <td>{{ number_format($s['avoirs_archives'], 0, ',', ' ') }}</td>
                        <td>{{ number_format($s['achats_actifs'], 0, ',', ' ') }}</td>
                        <td>{{ number_format($s['achats_archives'], 0, ',', ' ') }}</td>
                        <td>{{ number_format($s['points_de_vente'], 0, ',', ' ') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" style="padding:28px; text-align:center; color:var(--text-3);">Aucune entreprise.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<script>
(() => {
    const cible = document.getElementById('cibleNettoyage');
    const etat = document.getElementById('etatNettoyage');
    const action = document.getElementById('actionNettoyage');
    const entreprise = document.getElementById('entrepriseNettoyage');
    const confirmation = document.getElementById('confirmationNettoyage');
    const form = document.getElementById('formNettoyage');
    const bouton = document.getElementById('boutonNettoyage');

    function adapter() {
        const site = cible.value === 'points_de_vente';
        [...action.options].forEach(option => {
            option.hidden = site
                ? !['fermer', 'ouvrir', 'supprimer'].includes(option.value)
                : !['archiver', 'restaurer', 'purger'].includes(option.value);
        });
        [...etat.options].forEach(option => {
            option.hidden = site
                ? !['ouverts', 'fermes', 'tous'].includes(option.value)
                : !['actifs', 'archives', 'tous'].includes(option.value);
        });
        if (site && !['fermer', 'ouvrir', 'supprimer'].includes(action.value)) action.value = 'fermer';
        if (!site && !['archiver', 'restaurer', 'purger'].includes(action.value)) action.value = 'archiver';
        if (site && !['ouverts', 'fermes', 'tous'].includes(etat.value)) etat.value = 'ouverts';
        if (!site && !['actifs', 'archives', 'tous'].includes(etat.value)) etat.value = 'actifs';
        const destructif = action.value === 'purger' || action.value === 'supprimer';
        bouton.style.background = destructif ? '#b91c1c' : '';
        bouton.style.borderColor = destructif ? '#b91c1c' : '';
        bouton.innerHTML = destructif
            ? '<i class="fas fa-trash-can"></i> Confirmer la suppression'
            : '<i class="fas fa-broom"></i> Exécuter l’opération';
    }

    cible.addEventListener('change', adapter);
    action.addEventListener('change', adapter);
    etat.addEventListener('change', adapter);
    entreprise.addEventListener('change', () => {
        confirmation.value = '';
        const nom = entreprise.selectedOptions[0]?.dataset.nom || '';
        confirmation.placeholder = nom ? 'Saisir : ' + nom : '';
    });
    form.addEventListener('submit', event => {
        const destructif = action.value === 'purger' || action.value === 'supprimer';
        const nom = entreprise.selectedOptions[0]?.dataset.nom || '';
        if (confirmation.value.trim() !== nom) {
            event.preventDefault();
            alert('Le nom de l’entreprise doit être saisi exactement.');
            return;
        }
        if (destructif && !confirm('Dernière confirmation : cette suppression est définitive. Continuer ?')) {
            event.preventDefault();
        }
    });
    adapter();
})();
</script>
@endsection
