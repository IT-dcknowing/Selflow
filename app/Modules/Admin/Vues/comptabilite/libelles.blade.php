@extends('admin::gabarits.application')
@section('titre', "Libellés d'écriture")
@section('topbar_titre', "Comptabilité · Libellés d'écriture")

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-pen-nib"></i> Libellés d'écriture</h1>
        <p>Ce que le journal dit d'une opération, au lieu de répéter l'intitulé du compte.</p>
    </div>
</div>

{{-- La raison d'être de l'écran. Sans elle, un comptable qui l'ouvre ne voit
     que six champs à remplir sans savoir pourquoi ils existent. --}}
<div style="margin-bottom:18px; padding:14px 18px; background:#eff6ff; border:1px solid #93c5fd; border-radius:10px; color:var(--marque-900, #1e3a8a);">
    <strong><i class="fas fa-circle-info"></i> Le compte dit ce qu'il est ; le libellé doit dire ce que l'opération a été.</strong>
    <div style="font-size:12.5px; margin-top:6px; line-height:1.6;">
        Jusqu'ici, une facture de vente portait pour libellé « Vente de marchandises » —
        c'est-à-dire l'intitulé SYSCOHADA du compte mouvementé. Or le compte porte déjà ce nom :
        le répéter dépense la seule colonne de texte libre du journal. Un grand livre du
        <code>701</code> dont chaque ligne dit « Vente de marchandises » n'apprend rien à qui
        le relit ; ce qu'on veut y trouver, c'est <strong>quelle pièce, quel client, quels
        articles, quel site</strong>.
        <br><br>
        <strong>Les écritures déjà passées ne sont pas réécrites.</strong> Un journal se lit tel
        qu'il a été tenu. Le gabarit vaut pour ce qui s'écrira à partir de maintenant.
        Laisser les deux champs d'un type vides le ramène à son gabarit d'origine.
    </div>
</div>

@if(session('succes'))
    <div style="margin-bottom:18px; padding:14px 18px; background:#ecfdf5; border:1px solid #6ee7b7; border-radius:10px; color:#065f46;">
        <i class="fas fa-circle-check"></i> {{ session('succes') }}
    </div>
@endif

@if($errors->any())
    <div style="margin-bottom:18px; padding:14px 18px; background:#fef2f2; border:1px solid #fca5a5; border-radius:10px; color:#991b1b;">
        <i class="fas fa-triangle-exclamation"></i>
        <ul style="margin:6px 0 0 18px;">
            @foreach($errors->all() as $erreur)<li>{{ $erreur }}</li>@endforeach
        </ul>
    </div>
@endif

<div class="card" style="padding:16px 18px; margin-bottom:18px;">
    <h3 style="margin:0 0 10px; font-size:14.5px;"><i class="fas fa-tags"></i> Les jetons disponibles</h3>
    <p style="font-size:12.5px; color:#5f6672; margin:0 0 12px;">
        Cliquez sur un jeton pour l'insérer dans le champ où se trouve le curseur.
        Un jeton sans valeur — une vente sans client, un règlement sans référence —
        disparaît, et le séparateur qui l'annonçait avec lui.
    </p>
    <div style="display:flex; flex-wrap:wrap; gap:8px;">
        @foreach($jetons as $jeton => $explication)
            <button type="button" class="jeton" data-jeton="{{ $jeton }}"
                    title="{{ $explication }}"
                    style="border:1px solid #d8dce3; background:#f4f6f9; border-radius:999px;
                           padding:5px 12px; font-family:monospace; font-size:12px; cursor:pointer;">
                {{ $jeton }}
            </button>
        @endforeach
    </div>
</div>

<form method="POST" action="{{ route('admin.comptabilite.libelles.enregistrer') }}">
    @csrf
    @method('PUT')

    @foreach($types as $type => $nomLisible)
        @php
            $modele = $modeles[$type] ?? null;
            $defaut = $defauts[$type];
        @endphp
        <div class="card" style="padding:16px 18px; margin-bottom:14px;">
            <h3 style="margin:0 0 12px; font-size:15px;">{{ $nomLisible }}</h3>

            <div style="display:grid; grid-template-columns:1fr; gap:14px;">
                @foreach([['operation', "Libellé de l'opération", "Ce que le journal annonce en tête de l'opération."],
                          ['ligne', 'Libellé des lignes', "Ce que porte chaque ligne du grand livre. {role} y vaut « Facturation Vente », « TVA Collectée »… ou le nom des articles."]] as [$cible, $etiquette, $aide])
                    <div>
                        <label class="form-label" style="font-weight:700;">{{ $etiquette }}</label>
                        <input type="text"
                               class="form-control gabarit"
                               name="gabarits[{{ $type }}][{{ $cible }}]"
                               data-type="{{ $type }}"
                               data-cible="{{ $cible }}"
                               maxlength="255"
                               value="{{ old("gabarits.$type.$cible", $modele?->{'gabarit_' . $cible} ?? '') }}"
                               placeholder="{{ $defaut[$cible] }}">
                        <div style="font-size:12px; color:#5f6672; margin-top:4px;">{{ $aide }}</div>
                        <div style="font-size:12.5px; margin-top:6px;">
                            <span style="color:#5f6672;">Aperçu :</span>
                            <strong class="apercu" data-pour="{{ $type }}-{{ $cible }}"
                                    style="font-family:monospace;">—</strong>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    <div style="display:flex; gap:10px; margin:18px 0 30px;">
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-floppy-disk"></i> Enregistrer les libellés
        </button>
        <a href="{{ route('admin.comptabilite.grand_livre') }}" class="btn btn-secondary">
            <i class="fas fa-book"></i> Voir le grand livre
        </a>
    </div>
</form>

<script>
(function () {
    // Le jeu d'exemple vient du serveur : l'aperçu doit montrer exactement ce
    // que le service produira, pas une imitation écrite deux fois.
    const EXEMPLE  = @json($exemple);
    const DEFAUTS  = @json($defauts);
    const SEPS     = '\\/\\-–—,;|';

    let dernierChamp = null;

    // Le nettoyage reproduit celui de LibelleEcritureService. Le refaire ici
    // est un doublon assumé : un aller-retour au serveur à chaque frappe
    // rendrait l'aperçu saccadé, et la règle tient en trois lignes.
    function nettoyer(texte) {
        texte = texte.replace(/\s+/gu, ' ');
        texte = texte.replace(new RegExp('(\\s*[' + SEPS + ']\\s*)(?:[' + SEPS + ']\\s*)+', 'gu'), '$1');
        return texte.replace(new RegExp('^[\\s' + SEPS + ']+|[\\s' + SEPS + ']+$', 'gu'), '');
    }

    function appliquer(gabarit, cible) {
        let rendu = gabarit;
        for (const [nom, valeur] of Object.entries(EXEMPLE)) {
            // {role} n'a pas de sens sur l'opération : seule une ligne en porte un.
            const v = (cible === 'operation' && nom === 'role') ? '' : valeur;
            rendu = rendu.split('{' + nom + '}').join(v);
        }
        return nettoyer(rendu);
    }

    function rafraichir(champ) {
        const type   = champ.dataset.type;
        const cible  = champ.dataset.cible;
        const cellule = document.querySelector('.apercu[data-pour="' + type + '-' + cible + '"]');
        if (!cellule) return;

        const gabarit = champ.value.trim() || DEFAUTS[type][cible];
        const rendu = appliquer(gabarit, cible);

        cellule.textContent = rendu || '—';
        // Le gris dit « c'est le défaut qui parle » : sans cela, un champ vide
        // laisserait croire que le gabarit affiché a été saisi.
        cellule.style.color = champ.value.trim() ? '#1a1a1a' : '#5f6672';
    }

    document.querySelectorAll('.gabarit').forEach(function (champ) {
        rafraichir(champ);
        champ.addEventListener('input', function () { rafraichir(champ); });
        champ.addEventListener('focus', function () { dernierChamp = champ; });
    });

    document.querySelectorAll('.jeton').forEach(function (bouton) {
        bouton.addEventListener('click', function () {
            // Sans champ visé, le clic ne fait rien plutôt que d'écrire au
            // hasard dans le premier venu.
            if (!dernierChamp) return;

            const jeton = bouton.dataset.jeton;
            const debut = dernierChamp.selectionStart ?? dernierChamp.value.length;
            const fin   = dernierChamp.selectionEnd ?? dernierChamp.value.length;

            dernierChamp.value = dernierChamp.value.slice(0, debut) + jeton + dernierChamp.value.slice(fin);
            dernierChamp.focus();
            dernierChamp.setSelectionRange(debut + jeton.length, debut + jeton.length);
            rafraichir(dernierChamp);
        });
    });
})();
</script>
@endsection
