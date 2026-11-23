@extends('admin::gabarits.application')
@use('App\Modules\Admin\Modeles\PaiementAbonnement', 'Paiement')
@use('App\Modules\Admin\Regles\Abonnements', 'Abonnements')
@use('App\Modules\Admin\Regles\PaiementParReference', 'Reference')
@section('titre', 'Abonnement / réabonnement')
@section('topbar_titre', 'Superadmin · Abonnement / réabonnement')

@section('contenu')
<div style="max-width:1200px; margin:0 auto;">
    @if(session('succes'))<div class="alert alert-success" style="margin-bottom:12px;"><i class="fas fa-circle-check"></i> {{ session('succes') }}</div>@endif
    @if(session('erreur'))<div class="alert alert-danger" style="margin-bottom:12px;"><i class="fas fa-circle-exclamation"></i> {{ session('erreur') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger" style="margin-bottom:12px;"><i class="fas fa-circle-exclamation"></i> {{ $errors->first() }}</div>@endif

    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px;">
        <a href="{{ route('superadmin.paiements.index') }}" class="btn {{ $vue === 'abonnements' ? 'btn-primary' : 'btn-outline' }}">
            <i class="fas fa-calendar-check"></i> Abonnements
            @if($abonnements['a_terme']->count() + $abonnements['bientot']->count())<span style="margin-left:6px; background:#ef4444; color:#fff; border-radius:10px; padding:0 7px; font-size:11px;">{{ $abonnements['a_terme']->count() + $abonnements['bientot']->count() }}</span>@endif
        </a>
        <a href="{{ route('superadmin.paiements.index', ['vue' => 'paiements']) }}" class="btn {{ $vue === 'paiements' ? 'btn-primary' : 'btn-outline' }}">
            <i class="fas fa-money-check-dollar"></i> Paiements reçus
            @if($ouverts)<span style="margin-left:6px; background:#ef4444; color:#fff; border-radius:10px; padding:0 7px; font-size:11px;">{{ $ouverts }}</span>@endif
        </a>
    </div>

@if($vue === 'abonnements')
    @php
        $groupes = [
            'a_terme' => ['À terme', 'L\'échéance est passée : l\'entreprise est bloquée ou le sera à sa prochaine connexion.', '#b91c1c', '#fef2f2', 'fa-circle-xmark'],
            'bientot' => ['Bientôt à terme (10 jours ou moins)', 'À relancer, ou à prolonger dès maintenant.', '#b45309', '#fffbeb', 'fa-hourglass-half'],
            'en_cours' => ['En cours', 'Plus de 10 jours avant l\'échéance. Une prolongation s\'ajoute à l\'échéance actuelle.', '#047857', '#ecfdf5', 'fa-circle-check'],
        ];
        $offresProlongeables = collect(Abonnements::offres())->only([Abonnements::SOLO, Abonnements::BUSINESS, Abonnements::PREMIUM, Abonnements::SUR_MESURE]);
    @endphp
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin-bottom:16px;">
        @foreach($groupes as $cle => [$titre, $aide, $couleur, $fond, $icone])
            <a href="#groupe-{{ $cle }}" class="card" style="padding:14px 16px; text-decoration:none; border-left:4px solid {{ $couleur }};">
                <div style="font-size:12px; font-weight:800; color:{{ $couleur }}; text-transform:uppercase;"><i class="fas {{ $icone }}"></i> {{ $titre }}</div>
                <div style="font-size:26px; font-weight:800; color:var(--text);">{{ $abonnements[$cle]->count() }}</div>
            </a>
        @endforeach
    </div>
    @if($aConfirmer->isNotEmpty())
        <div class="card" style="padding:0; margin-bottom:16px; overflow-x:auto; border-left:4px solid #2563eb;">
            <div style="padding:12px 18px; border-bottom:1px solid var(--border);">
                <strong><i class="fas fa-money-check-dollar"></i> Paiements à confirmer ({{ $aConfirmer->count() }})</strong>
                <div style="font-size:12.5px; color:var(--text-2);">Références déposées par les entreprises, et échecs des 30 derniers jours qui peuvent encore être confirmés.</div>
            </div>
            <table class="table" style="width:100%;">
                <thead><tr><th>Reçu le</th><th>Entreprise</th><th>Offre</th><th>Montant</th><th>Paiement</th><th>Statut</th></tr></thead>
                <tbody>
                @foreach($aConfirmer as $p)
                    @include('admin::superadmin.paiements._ligne', ['p' => $p])
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($sansEcheance)
        <p style="font-size:12.5px; color:var(--text-3); margin:-6px 0 14px;">{{ $sansEcheance }} entreprise{{ $sansEcheance > 1 ? 's' : '' }} sans échéance (essai, inscription en attente ou offre à choisir) : elles se gèrent depuis Entreprises.</p>
    @endif

    @foreach($groupes as $cle => [$titre, $aide, $couleur, $fond, $icone])
        <div class="card" id="groupe-{{ $cle }}" style="padding:0; margin-bottom:16px; overflow:hidden;">
            <div style="padding:12px 18px; background:{{ $fond }}; border-bottom:1px solid var(--border);">
                <strong style="color:{{ $couleur }};"><i class="fas {{ $icone }}"></i> {{ $titre }} ({{ $abonnements[$cle]->count() }})</strong>
                <div style="font-size:12.5px; color:var(--text-2);">{{ $aide }}</div>
            </div>
            @forelse($abonnements[$cle] as $e)
                @php
                    $jours = (int) now()->startOfDay()->diffInDays($e->abonnement_expire_le->copy()->startOfDay(), false);
                @endphp
                <div style="display:flex; justify-content:space-between; gap:14px; flex-wrap:wrap; align-items:center; padding:12px 18px; border-bottom:1px solid var(--border);">
                    <div style="min-width:220px;">
                        <strong>{{ $e->nom }}</strong>
                        <div style="font-size:12px; color:var(--text-3);">
                            {{ Abonnements::libelle($e->plan_abonnement) }} · échéance le {{ $e->abonnement_expire_le->format('d/m/Y') }}
                            · <strong style="color:{{ $couleur }};">{{ $jours < 0 ? 'échu depuis ' . abs($jours) . ' j' : ($jours === 0 ? 'aujourd\'hui' : $jours . ' j restants') }}</strong>
                        </div>
                    </div>
                    <details style="flex:1; min-width:280px; max-width:640px;">
                        <summary class="btn btn-outline btn-sm" style="list-style:none; display:inline-flex; float:right;"><i class="fas fa-rotate"></i> Prolonger / mettre à jour</summary>
                        <form method="POST" action="{{ route('superadmin.paiements.prolonger', $e) }}" style="clear:both; display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:8px; align-items:end; padding-top:10px;"
                              onsubmit="return confirm('Enregistrer la mise à jour de l\'abonnement de {{ addslashes($e->nom) }} ?');">
                            @csrf
                            <label style="font-size:12px; font-weight:700;">Offre
                                <select name="offre" class="form-control">
                                    @foreach($offresProlongeables as $code => $o)
                                        <option value="{{ $code }}" @selected($e->plan_abonnement === $code)>{{ $o['nom'] }}{{ $o['prix'] ? ' · ' . number_format($o['prix'], 0, ',', ' ') . ' F/mois' : '' }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label style="font-size:12px; font-weight:700;">Ajouter
                                <select name="mois" class="form-control">
                                    <option value="0">0 mois</option>
                                    @foreach([1, 2, 3, 6, 12, 24] as $m)<option value="{{ $m }}" @selected($m === 1)>{{ $m }} mois</option>@endforeach
                                </select>
                            </label>
                            <label style="font-size:12px; font-weight:700;">Ou échéance précise
                                <input type="date" name="echeance" class="form-control" min="{{ now()->addDay()->toDateString() }}">
                            </label>
                            <label style="font-size:12px; font-weight:700;">Montant réglé (F)
                                <input type="number" name="montant_total" class="form-control" min="0" step="1" placeholder="Tarif × mois">
                            </label>
                            <button class="btn btn-primary btn-sm" style="height:38px;"><i class="fas fa-check"></i> Enregistrer</button>
                        </form>
                        <div style="font-size:11.5px; color:var(--text-3); padding-top:6px;">Les mois s'ajoutent à l'échéance actuelle si elle n'est pas passée, sinon à aujourd'hui. Une échéance précise remplace ce calcul. Chaque prolongation est inscrite aux paiements.</div>
                    </details>
                </div>
            @empty
                <p style="padding:16px 18px; color:var(--text-3); margin:0;">Aucune entreprise.</p>
            @endforelse
        </div>
    @endforeach
@else
    <div class="card" style="padding:16px 20px; margin-bottom:16px; font-size:13px; color:var(--text-2); line-height:1.7;">
        <i class="fas fa-circle-info" style="color:var(--primary);"></i>
        En attendant l'API de paiement, les entreprises déposent le montant sur le numéro du cabinet et saisissent la
        <strong>référence de la transaction</strong>. Vérifiez-la, puis <strong>confirmez et activez</strong> : l'échéance
        est repoussée d'un mois, et l'entreprise, bloquée en attendant, est débloquée. Un paiement mis en
        <strong>échec</strong> peut encore être confirmé, si le règlement est retrouvé.
        <strong>{{ $ouverts }}</strong> paiement{{ $ouverts > 1 ? 's' : '' }} à traiter.
    </div>

    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px;">
        <a href="{{ route('superadmin.paiements.index', ['vue' => 'paiements']) }}" class="btn {{ $statut ? 'btn-outline' : 'btn-primary' }} btn-sm">Tous</a>
        @foreach(Paiement::STATUTS as $cle => $libelle)
            <a href="{{ route('superadmin.paiements.index', ['statut' => $cle]) }}" class="btn {{ $statut === $cle ? 'btn-primary' : 'btn-outline' }} btn-sm">{{ $libelle }}</a>
        @endforeach
    </div>

    <div class="card" style="padding:0; overflow-x:auto;">
        @if($paiements->isEmpty())
            <p style="padding:30px; text-align:center; color:var(--text-3);">Aucun paiement.</p>
        @else
        <table class="table" style="width:100%;">
            <thead><tr><th>Reçu le</th><th>Entreprise</th><th>Offre</th><th>Montant</th><th>Paiement</th><th>Statut</th></tr></thead>
            <tbody>
            @foreach($paiements as $p)
                @include('admin::superadmin.paiements._ligne', ['p' => $p])
            @endforeach
            </tbody>
        </table>
        <div style="padding:0 16px;">{{ $paiements->links() }}</div>
        @endif
    </div>
@endif
</div>
@endsection
