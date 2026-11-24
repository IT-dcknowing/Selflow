@php
    // Année de l'exercice ouvert : c'est elle qui donne son sens au numéro de
    // semaine, et c'est elle que le serveur retient. La prendre de l'horloge du
    // navigateur décalerait les libellés dès qu'un exercice n'épouse pas
    // l'année civile.
    $anneeExercice = (int) date('Y', strtotime(session('active_periode_debut') ?: 'now'));
@endphp
{{--
    Filtre de période des écrans FNE.

    Il reprend exactement celui du tableau de bord général — mois, semaine,
    jour, remise à zéro — et y ajoute le point de vente, propre à ces écrans.

    Les deux familles d'écrans filtraient auparavant differemment : le tableau
    de bord général par mois / semaine / jour à l'intérieur de la période
    comptable active, les écrans FNE par type de période et date de référence,
    sans considérer l'exercice ouvert. Deux pages ouvertes côte à côte
    annonçaient donc des chiffres différents pour ce que l'utilisateur croyait
    être le même périmètre.

    Le rafraîchissement est confié à la fonction `$onChange` de l'écran hôte.
--}}
<div class="fne-filtres">
    <div style="display:flex; align-items:center; gap:6px; font-size:12px; font-weight:700; color:var(--text-3); text-transform:uppercase; letter-spacing:.4px;">
        <i class="fas fa-filter"></i> Filtrer par
    </div>

    <div class="form-group">
        <label>Mois</label>
        <select id="f-mois" class="form-control" data-annee="{{ $anneeExercice }}"
                onchange="rafraichirSemainesFne(); {{ $onChange }}">
            <option value="tous">Tous les mois</option>
            @foreach(['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'] as $i => $nom)
                <option value="{{ $i + 1 }}">{{ $nom }}</option>
            @endforeach
        </select>
    </div>

    <div class="form-group">
        <label>Semaine</label>
        <select id="f-semaine" class="form-control" onchange="{{ $onChange }}">
            <option value="tous">Toutes les semaines</option>
            @for($s = 1; $s <= 53; $s++)
                <option value="{{ $s }}">Semaine {{ $s }}</option>
            @endfor
        </select>
    </div>

    <div class="form-group">
        <label>Jour</label>
        <select id="f-jour" class="form-control" onchange="{{ $onChange }}">
            <option value="tous">Tous les jours</option>
            @for($j = 1; $j <= 31; $j++)
                <option value="{{ $j }}">{{ $j }}</option>
            @endfor
        </select>
    </div>

    <div class="form-group">
        <label>Point de vente</label>
        <select id="f-pdv" class="form-control" onchange="{{ $onChange }}">
            <option value="tous">Tous les sites</option>
            @foreach($pointsDeVente as $pdv)
                <option value="{{ $pdv->id }}">{{ $pdv->nom }}</option>
            @endforeach
        </select>
    </div>

    @isset($avant)
        @include($avant)
    @endisset

    <button type="button" class="btn btn-primary" onclick="window.actualiserToutFne ? actualiserToutFne(this, function () { {{ $onChange }} }) : {{ $onChange }}">
        <i class="fas fa-rotate"></i> Actualiser
    </button>
    <button class="btn btn-outline" onclick="reinitialiserFiltresFne(); {{ $onChange }}">
        <i class="fas fa-rotate-left"></i> Reset
    </button>

    @isset($apres)
        @include($apres)
    @endisset
</div>

<script>
/**
 * Parametres de filtrage communs aux ecrans FNE, lus tels que le serveur les
 * attend (memes noms que sur le tableau de bord general, pour que les deux
 * resolvent la meme periode).
 */
function parametresFiltreFne() {
    return new URLSearchParams({
        filtre_mois:    document.getElementById('f-mois').value,
        filtre_semaine: document.getElementById('f-semaine').value,
        filtre_jour:    document.getElementById('f-jour').value,
        pdv_id:         document.getElementById('f-pdv').value,
    });
}

/**
 * Semaines proposees pour le mois choisi, numerotees 1..N comme sur le tableau
 * de bord general — la valeur envoyee reste le numero de semaine ISO, que le
 * serveur attend. Sans cela, « Semaine 3 » ne designait pas la meme semaine
 * d'un ecran a l'autre.
 */
function rafraichirSemainesFne() {
    var moisSelect = document.getElementById('f-mois');
    var semaineSelect = document.getElementById('f-semaine');
    if (!moisSelect || !semaineSelect) return;

    var moisVal = moisSelect.value;
    var choisie = semaineSelect.value;

    semaineSelect.innerHTML = '<option value="tous">Toutes les semaines</option>';

    if (moisVal === 'tous') {
        for (var s = 1; s <= 53; s++) {
            semaineSelect.innerHTML += '<option value="' + s + '">Semaine ' + s + '</option>';
        }
    } else {
        var annee = parseInt(moisSelect.dataset.annee, 10);
        var m = parseInt(moisVal, 10) - 1;
        var semaines = [];

        for (var d = new Date(annee, m, 1); d <= new Date(annee, m + 1, 0); d.setDate(d.getDate() + 1)) {
            // Numero de semaine ISO : on se cale sur le jeudi de la semaine.
            var jeudi = new Date(d.valueOf());
            jeudi.setDate(jeudi.getDate() + 4 - (jeudi.getDay() || 7));
            var debutAnnee = new Date(jeudi.getFullYear(), 0, 1);
            var numero = Math.ceil((((jeudi - debutAnnee) / 86400000) + 1) / 7);
            if (semaines.indexOf(numero) === -1) semaines.push(numero);
        }

        semaines.sort(function (a, b) { return a - b; }).forEach(function (numero, i) {
            semaineSelect.innerHTML += '<option value="' + numero + '">Semaine ' + (i + 1) + '</option>';
        });
    }

    semaineSelect.value = semaineSelect.querySelector('option[value="' + choisie + '"]') ? choisie : 'tous';
}

/**
 * Remise a zero : retour a la periode comptable active, tous sites confondus.
 * Le rafraichissement suit dans le meme `onclick`, cote ecran hote.
 */
function reinitialiserFiltresFne() {
    ['f-mois', 'f-semaine', 'f-jour', 'f-pdv'].forEach(function (id) {
        var champ = document.getElementById(id);
        if (champ) champ.value = 'tous';
    });

    // La recherche libre n'existe que sur le registre.
    var recherche = document.getElementById('f-recherche');
    if (recherche) recherche.value = '';

    rafraichirSemainesFne();
}
</script>
