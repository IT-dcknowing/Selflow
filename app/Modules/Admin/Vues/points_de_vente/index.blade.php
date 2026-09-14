@extends('admin::gabarits.application')
@section('titre', 'Points de vente')
@section('topbar_titre', 'Infrastructure — Points de vente')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-store"></i> Points de vente</h1>
        <p>{{ $pointsDeVente->count() }} / {{ $quotaMax }} points de vente utilisés</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
        <button class="btn btn-outline" onclick="ouvrirImport('modalImportPdv')" style="font-size:13px;">
            <i class="fas fa-file-import"></i> Importer CSV
        </button>
        @if($pointsDeVente->count() < $quotaMax)
        <button class="btn btn-primary" data-modal-open="modalNouveauPdv">
            <i class="fas fa-plus"></i> Nouveau point de vente
        </button>
        @endif
    </div>
</div>

{{-- Barre de progression quota --}}
<div style="margin-bottom: 22px; background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); padding: 18px 22px;">
    <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
        <span style="font-weight:600; font-size:13px;">Quota d'abonnement</span>
        <span style="font-size:13px; color:var(--text-2);">{{ $pointsDeVente->count() }} / {{ $quotaMax }} utilisés</span>
    </div>
    <div style="background:var(--bg3); border-radius:99px; height:8px; overflow:hidden;">
        @php $pct = $quotaMax > 0 ? ($pointsDeVente->count() / $quotaMax) * 100 : 0; @endphp
        <div style="height:100%; width:{{ $pct }}%; background: linear-gradient(90deg, var(--primary), #818cf8); border-radius:99px; transition: width .4s;"></div>
    </div>
</div>

{{-- Les points de facturation déclarés au portail FNE.
     Le sens ne s'inverse jamais : le portail déclare, Selflow s'aligne. Rien
     n'est créé au portail depuis ici — c'est un acte du contribuable. --}}
<div class="card" style="margin-bottom:22px;">
    <div class="card-body">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap;">
            <div>
                <div style="font-size:15px; font-weight:800;">
                    <i class="fas fa-building-columns"></i> Points de facturation déclarés au portail FNE
                </div>
                <div style="font-size:12px; color:var(--text-3); margin-top:4px;">
                    @if($portailFne['releve_le'])
                        Relevé du {{ $portailFne['releve_le'] }}. C'est ce nom-là que la DGI attend :
                        une facture émise sous un autre est refusée.
                    @else
                        Aucun relevé pour votre entreprise. Le portail est relevé chaque nuit ;
                        rien n'a encore été rangé pour ce NCC.
                    @endif
                </div>
            </div>

            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                {{-- Aller voir le portail maintenant : le passage horaire n'y va
                     que si une pièce a été refusée, et le passage complet attend
                     02:30. Celui qui vient d'y déclarer un point n'a pas à
                     attendre demain matin. --}}
<form method="POST" action="{{ route('admin.pdv.relever_le_portail') }}" id="formReleverPortail">
                    @csrf
                    <button type="submit" class="btn btn-outline" style="font-size:13px;" id="boutonReleverPortail">
                        <i class="fas fa-rotate"></i> Relever le portail maintenant
                    </button>
                </form>
                <span id="etatReleverPortail" style="font-size:12px; color:var(--text-3);"></span>

                @if($portailFne['a_creer'] > 0)
                <form method="POST" action="{{ route('admin.pdv.importer_du_portail') }}">
                    @csrf
                    <button type="submit" class="btn btn-primary" style="font-size:13px;">
                        <i class="fas fa-download"></i>
                        Reprendre {{ $portailFne['a_creer'] }} point(s) manquant(s)
                    </button>
                </form>
                @endif

                {{-- Charger le relevé à la main. Le scraper ne tourne pas
                     partout — poste sans Node, second facteur au portail — et
                     l'entreprise a pourtant le fichier sous les yeux, exporté
                     depuis son espace FNE. Le champ se déclenche au clic du
                     bouton : un « parcourir » nu à côté de deux boutons pleins
                     ne se lit pas comme une action. --}}
                <form method="POST" action="{{ route('admin.pdv.load_file_fne') }}"
                      enctype="multipart/form-data" id="formChargerFichierFne">
                    @csrf
                    <input type="file" name="fichier_fne" id="fichierFne" accept=".json,.xlsx,.xls"
                           style="display:none;" onchange="document.getElementById('formChargerFichierFne').submit();">
                    <button type="button" class="btn btn-outline" style="font-size:13px;"
                            onclick="document.getElementById('fichierFne').click();"
                            title="Fiche (.json) ou points de facturation (.xlsx, .xls) exportés du portail FNE. Le fichier doit être nommé NCC_AAAAMMJJ — par exemple 1864699A_20260831.xlsx">
                        <i class="fas fa-file-arrow-up"></i> Charger un relevé
                    </button>
                </form>
            </div>
        </div>

        @error('fichier_fne')
        <div style="margin-top:10px; font-size:12px; color:var(--danger, #c0392b);">
            <i class="fas fa-circle-exclamation"></i> {{ $message }}
        </div>
        @enderror

        @if($portailFne['points'])
        <div style="margin-top:16px; display:flex; flex-direction:column; gap:8px;">
            @foreach($portailFne['points'] as $point)
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;
                        background:var(--bg3); border-radius:8px; padding:10px 14px;">
                <div>
                    <span style="font-weight:700; font-size:13px;">{{ $point['nom'] }}</span>
                    @unless($point['actif'])
                        <span class="badge badge-gray" style="margin-left:8px;">Inactif au portail</span>
                    @endunless
                </div>
                @if($point['point_de_vente'])
                    <span class="badge badge-success">Dans Selflow</span>
                @else
                    <span class="badge badge-warning">À créer</span>
                @endif
            </div>
            @endforeach
        </div>
        @endif

        @if($portailFne['inconnus_du_portail'])
        <div style="margin-top:14px; font-size:12px; color:var(--text-2);">
            <i class="fas fa-triangle-exclamation"></i>
            Dans Selflow et inconnu(s) du portail :
            <strong>{{ collect($portailFne['inconnus_du_portail'])->pluck('nom')->join(', ') }}</strong>.
            Une facture émise depuis l'un d'eux sera refusée par la DGI tant que le point
            n'aura pas été déclaré sur l'espace FNE — Selflow ne peut pas le déclarer à votre place.
        </div>
        @endif
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 18px;">
    @foreach($pointsDeVente as $pdv)
    <div class="card" style="transition: transform .2s;">
        <div class="card-body">
            <div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:14px;">
                <div>
                    <div style="font-size:16px; font-weight:800;">{{ $pdv->nom }}</div>
                    <div style="font-size:12px; color:var(--text-3); margin-top:3px;">
                        <i class="fas fa-location-dot"></i> {{ $pdv->commune }}, {{ $pdv->ville }}
                    </div>
                </div>
                @if($pdv->statut === 'Ouvert')
                    <span class="badge badge-success">Ouvert</span>
                @else
                    <span class="badge badge-gray">Fermé</span>
                @endif
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px;">
                <div style="background:var(--bg3); border-radius:8px; padding:12px; text-align:center;">
                    <div style="font-size:20px; font-weight:800; color:var(--primary);">{{ $pdv->ventes_count }}</div>
                    <div style="font-size:11px; color:var(--text-3);">Ventes totales</div>
                </div>
                <div style="background:var(--bg3); border-radius:8px; padding:12px; text-align:center;">
                    <div style="font-size:20px; font-weight:800; color:var(--success);">{{ $pdv->utilisateurs_count }}</div>
                    <div style="font-size:11px; color:var(--text-3);">Utilisateurs</div>
                </div>
            </div>

            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px;">
                <button type="button" class="btn btn-outline" data-modal-open="modalModifierPdv-{{ $pdv->id }}" style="min-width:160px; justify-content:center;">
                    <i class="fas fa-edit"></i> Modifier le nom
                </button>
            </div>

            <div style="font-size:13px; color:var(--text-2); margin-bottom:16px;">
                @if($pdv->responsable)
                <div><i class="fas fa-user" style="width:16px;"></i> {{ $pdv->responsable }}</div>
                @endif
                @if($pdv->telephone)
                <div style="margin-top:4px;"><i class="fas fa-phone" style="width:16px;"></i> {{ $pdv->telephone }}</div>
                @endif
            </div>

            @if($pdv->nom !== 'Siège')
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <form method="POST" action="{{ route('admin.pdv.activer', $pdv) }}">
                    @csrf
                    @if(session('point_de_vente_actif_id') == $pdv->id && !session()->has('apercu_pdv_id'))
                        <button type="button" class="btn btn-success" style="width:100%; justify-content:center;" disabled>
                            <i class="fas fa-check-circle"></i> Point de vente actif
                        </button>
                    @else
                        <button type="submit" class="btn btn-outline" style="width:100%; justify-content:center;">
                            <i class="fas fa-toggle-on"></i> Activer pour cette session
                        </button>
                    @endif
                </form>

                <form method="POST" action="{{ route('admin.pdv.activer_apercu', $pdv) }}">
                    @csrf
                    @if(session('apercu_pdv_id') == $pdv->id)
                        <button type="button" class="btn btn-success" style="width:100%; justify-content:center; background:#D97706; border-color:#D97706; color:#fff;" disabled>
                            <i class="fas fa-eye"></i> Aperçu actif
                        </button>
                    @else
                        <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; background:#6C5CE7; border-color:#6C5CE7; color:#fff;">
                            <i class="fas fa-eye"></i> Apercevoir l'interface caissier
                        </button>
                    @endif
                </form>
            </div>
            @else
            <div style="text-align: center; font-size: 12.5px; color: var(--primary); font-weight: 600; padding: 10px; border: 1px dashed rgba(0, 43, 92, 0.3); border-radius: 8px; background: rgba(0, 43, 92, 0.03);">
                <i class="fas fa-building"></i> Point de vente principal (Siège)
            </div>
            @endif

            <div class="modal-overlay" id="modalModifierPdv-{{ $pdv->id }}">
                <div class="modal">
                    <div class="modal-header">
                        <h3><i class="fas fa-edit"></i> Modifier Point de vente</h3>
                        <button class="modal-close" data-modal-close>✕</button>
                    </div>
                    <form method="POST" action="{{ route('admin.pdv.modifier', $pdv) }}">
                        @csrf
                        @method('PUT')
                        <div class="form-grid-2" style="gap:14px;">
                            <div class="form-group">
                                <label class="form-label">Nom <span style="color:var(--danger)">*</span></label>
                                <input type="text" name="nom" class="form-control" value="{{ old('nom', $pdv->nom) }}" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Ville <span style="color:var(--danger)">*</span></label>
                                <input type="text" name="ville" class="form-control" value="{{ old('ville', $pdv->ville) }}" required>
                            </div>
                        </div>
                        <div class="form-grid-2" style="gap:14px; margin-top:12px;">
                            <div class="form-group">
                                <label class="form-label">Commune</label>
                                <input type="text" name="commune" class="form-control" value="{{ old('commune', $pdv->commune) }}">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Téléphone</label>
                                <input type="text" name="telephone" class="form-control" value="{{ old('telephone', $pdv->telephone) }}">
                            </div>
                        </div>
                        <div class="form-group" style="margin-top:12px;">
                            <label class="form-label">Responsable</label>
                            <input type="text" name="responsable" class="form-control" value="{{ old('responsable', $pdv->responsable) }}">
                        </div>
                        <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:16px;">
                            <button type="button" class="btn btn-outline" data-modal-close>Annuler</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Enregistrer</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    @endforeach
</div>

{{-- Modal Nouveau PDV --}}
<div class="modal-overlay" id="modalNouveauPdv">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-store"></i> Nouveau point de vente</h3>
            <button class="modal-close" data-modal-close>✕</button>
        </div>
        <form method="POST" action="{{ route('admin.pdv.creer') }}">
            @csrf
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">Nom <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="nom" class="form-control" placeholder="Ex: Agence Nord" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Ville <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="ville" class="form-control" placeholder="Ex: Abidjan" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Commune</label>
                    <input type="text" name="commune" class="form-control" placeholder="Ex: Cocody">
                </div>
                <div class="form-group">
                    <label class="form-label">Téléphone</label>
                    <input type="text" name="telephone" class="form-control" placeholder="+225 07 ...">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Responsable</label>
                <input type="text" name="responsable" class="form-control" placeholder="Nom du responsable">
            </div>
            <small class="text-muted" style="display:block; margin-bottom:8px;">
                Le <strong>nom du point de vente</strong> est la valeur transmise à la DGI dans le champ
                <code>pointOfSale</code> : il doit correspondre exactement à celui déclaré dans votre espace FNE.
            </small>
            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:8px;">
                <button type="button" class="btn btn-outline" data-modal-close>Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Créer</button>
            </div>
        </form>
    </div>
</div>
@include('admin::composants.modal-import', ['type' => 'points-de-vente', 'label' => 'Points de vente', 'id' => 'modalImportPdv'])

{{-- Le relevé ouvre un vrai navigateur sur le portail de la DGI : il dure des
     dizaines de secondes. Plutôt que d'annoncer « rechargez dans une minute » —
     une attente que personne ne doit avoir à tenir —, la page redemande l'état
     du portail et se recharge d'elle-même dès qu'il a changé. Sans JavaScript,
     le formulaire part normalement et le relevé tourne quand même. --}}
<script>
(function () {
    const formulaire = document.getElementById('formReleverPortail');
    const bouton     = document.getElementById('boutonReleverPortail');
    const etat       = document.getElementById('etatReleverPortail');

    if (!formulaire || !bouton || !etat) return;

    const empreinteAffichee = @json($portailFne['empreinte'] ?? '');
    const deposeAffiche     = @json($portailFne['depose_le'] ?? null);
    const urlEtat           = @json(route('admin.pdv.etat_du_portail'));
    const jeton             = formulaire.querySelector('input[name="_token"]').value;

    // Toutes les trois secondes, pendant trois minutes au plus : un relevé qui
    // n'arrive pas rend la main plutôt que d'interroger le serveur sans fin.
    // Une connexion au portail, deux téléchargements et la liste des factures
    // reçues tiennent en une minute d'ordinaire — trois laissent de la marge un
    // jour de portail lent.
    const PERIODE = 3000, LIMITE = 60;
    let essais = 0, minuteur = null;

    function arreter(message) {
        clearInterval(minuteur);
        bouton.disabled = false;
        bouton.innerHTML = '<i class="fas fa-rotate"></i> Relever le portail maintenant';
        etat.textContent = message;
    }

    async function regarder() {
        if (++essais > LIMITE) {
            arreter("Le relevé n'est pas arrivé. Réessayez, ou attendez le passage automatique.");
            return;
        }

        try {
            const reponse = await fetch(urlEtat, { headers: { 'Accept': 'application/json' } });
            if (!reponse.ok) return;

            const donnees = await reponse.json();

            // L'empreinte a bougé : le portail a dit quelque chose de neuf.
            if (donnees.empreinte && donnees.empreinte !== empreinteAffichee) {
                clearInterval(minuteur);
                etat.textContent = "Relevé arrivé — mise à jour de l'écran…";
                window.location.reload();
                return;
            }

            // Le scraper a déposé, mais le portail redit ce qu'il disait déjà.
            // C'est un succès, pas une panne : le dire, plutôt que d'attendre
            // trois minutes pour annoncer que rien n'est arrivé.
            if (donnees.depose_le && donnees.depose_le !== deposeAffiche) {
                arreter('Relevé arrivé : le portail ne déclare rien de nouveau.');
            }
        } catch (_) {
            // Une requête perdue n'arrête pas la surveillance.
        }
    }

    formulaire.addEventListener('submit', async function (evenement) {
        evenement.preventDefault();

        // Une surveillance à la fois : sans cela, deux clics faisaient courir
        // deux minuteurs sur le même compteur d'essais, qui atteignait sa
        // limite deux fois plus vite — et l'écran annonçait « le relevé n'est
        // pas arrivé » avant même que le premier ait eu le temps d'arriver.
        clearInterval(minuteur);

        bouton.disabled = true;
        bouton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Relevé en cours…';
        etat.textContent = 'Connexion au portail de la DGI…';

        try {
            const reponse = await fetch(formulaire.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': jeton,
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            const donnees = await reponse.json();

            if (!reponse.ok) {
                arreter(donnees.message || "Le relevé n'a pas pu être lancé.");
                return;
            }

            essais = 0;
            minuteur = setInterval(regarder, PERIODE);
        } catch (_) {
            arreter("Le relevé n'a pas pu être lancé.");
        }
    });
})();
</script>

@endsection
