{{--
    Ce que le mot de passe doit contenir, dit sous le champ et coché en direct
    (retest de production : « ajouter une précision sur les types de
    caractères à saisir »). Seule la longueur est exigée par le serveur ; les
    quatre autres règles sont conseillées, et la liste le dit.

    Paramètres : `champMdp` (id du champ, `password` par défaut), `avecJauge`
    (une barre de solidité au-dessus de la liste — l'écran de création du
    superadministrateur, 15/10/2026).
--}}
@php $champMdp = $champMdp ?? 'password'; $avecJauge = $avecJauge ?? false; @endphp
@if($avecJauge)
<div class="jauge-mdp" style="margin-top:8px;">
    <div style="height:6px; background:#e2e8f0; border-radius:4px; overflow:hidden;">
        <div id="jauge-mdp-barre" style="height:100%; width:0; background:#ef4444; transition:width .2s, background .2s;"></div>
    </div>
    <small id="jauge-mdp-libelle" style="font-size:11px; color:#64748b;">Solidité du mot de passe</small>
</div>
@endif
<ul class="regles-mdp" id="regles-mdp" aria-live="polite"
    style="list-style:none; margin:8px 0 0; padding:10px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; font-size:12px; line-height:1.9; color:#64748b;">
    <li data-regle="longueur"><i class="fa-solid fa-circle-xmark"></i> <strong>8 caractères au moins</strong> (obligatoire)</li>
    <li data-regle="majuscule"><i class="fa-solid fa-circle-xmark"></i> une lettre majuscule (A-Z)</li>
    <li data-regle="minuscule"><i class="fa-solid fa-circle-xmark"></i> une lettre minuscule (a-z)</li>
    <li data-regle="chiffre"><i class="fa-solid fa-circle-xmark"></i> un chiffre (0-9)</li>
    <li data-regle="special"><i class="fa-solid fa-circle-xmark"></i> un caractère spécial (! @ # $ % & * ? - _ …)</li>
    <li style="margin-top:4px; font-size:11.5px;">Les accents et les espaces sont acceptés. Évitez votre nom et votre date de naissance.</li>
</ul>
<script>
(function () {
    var champ = document.getElementById(@json($champMdp));
    var liste = document.getElementById('regles-mdp');
    if (!champ || !liste) return;
    var regles = {
        longueur: function (v) { return v.length >= 8; },
        majuscule: function (v) { return /[A-ZÀ-ÖØ-Þ]/.test(v); },
        minuscule: function (v) { return /[a-zß-öø-ÿ]/.test(v); },
        chiffre: function (v) { return /[0-9]/.test(v); },
        special: function (v) { return /[^A-Za-zÀ-ÖØ-öø-ÿ0-9\s]/.test(v); }
    };
    var barre = document.getElementById('jauge-mdp-barre');
    var libelle = document.getElementById('jauge-mdp-libelle');
    function verifier() {
        var v = champ.value, total = 0;
        Object.keys(regles).forEach(function (cle) {
            var li = liste.querySelector('[data-regle="' + cle + '"]');
            var ok = regles[cle](v);
            if (ok) total++;
            li.style.color = ok ? '#047857' : '#64748b';
            li.querySelector('i').className = ok ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark';
        });
        if (barre) {
            var niveaux = [['Solidité du mot de passe', '#ef4444'], ['Très faible', '#ef4444'], ['Faible', '#f97316'], ['Moyen', '#f59e0b'], ['Bon', '#22c55e'], ['Excellent', '#047857']];
            var n = v ? total : 0;
            barre.style.width = (n * 20) + '%';
            barre.style.background = niveaux[n][1];
            libelle.textContent = niveaux[n][0];
        }
    }
    champ.addEventListener('input', verifier);
    verifier();
})();
</script>
