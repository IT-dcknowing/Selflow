@extends('admin::gabarits.application')
@use('App\Modules\Admin\Modeles\Entreprise', 'Entreprise')
@section('titre', 'Corbeille')
@section('topbar_titre', 'SuperAdmin — Corbeille')

@section('contenu')
<div style="max-width:1100px; margin:0 auto;">
    <div class="card" style="padding:16px 20px; margin-bottom:16px; font-size:13px; color:var(--text-2); line-height:1.7;">
        <i class="fas fa-circle-info" style="color:var(--primary);"></i>
        Une entreprise supprimée vient ici. Elle n'a plus accès à la plateforme, mais rien n'est effacé :
        vous pouvez la <strong>restaurer</strong> pendant <strong>{{ Entreprise::JOURS_EN_CORBEILLE }} jours</strong>.
        Passé ce délai, elle est effacée définitivement — ou tout de suite si vous videz la corbeille.
        <strong>{{ $entreprises->total() }}</strong> entreprise{{ $entreprises->total() > 1 ? 's' : '' }} en corbeille.
    </div>

    @if(session('succes'))
        <div style="background:#ecfdf5; border:1px solid #6ee7b7; color:#065f46; border-radius:10px; padding:12px 16px; margin-bottom:14px; font-weight:600;">{{ session('succes') }}</div>
    @endif
    @if(session('erreur'))
        <div style="background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; border-radius:10px; padding:12px 16px; margin-bottom:14px; font-weight:600;">{{ session('erreur') }}</div>
    @endif

    @if($entreprises->isNotEmpty())
    <div style="display:flex; justify-content:flex-end; margin-bottom:12px;">
        <form method="POST" action="{{ route('superadmin.corbeille.vider_tout') }}" style="margin:0;"
              onsubmit="return confirm('Vider toute la corbeille ? Les {{ $entreprises->total() }} entreprise(s) seront effacées définitivement et sans retour.');">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-sm" style="background:#dc2626; color:#fff; border:none;"><i class="fas fa-trash-can"></i> Vider la corbeille</button>
        </form>
    </div>
    @endif

    <div class="card" style="padding:0; overflow-x:auto;">
        @if($entreprises->isEmpty())
            <p style="padding:34px; text-align:center; color:var(--text-3);"><i class="fas fa-trash-can"></i> La corbeille est vide.</p>
        @else
        <table class="table" style="width:100%;">
            <thead><tr><th>Entreprise</th><th>Supprimée le</th><th>Utilisateurs</th><th>Effacement dans</th><th style="text-align:right;">Actions</th></tr></thead>
            <tbody>
            @foreach($entreprises as $e)
                @php $jours = $e->joursAvantEffacement(); @endphp
                <tr>
                    <td><strong>{{ $e->nom }}</strong>
                        <div style="font-size:11.5px; color:var(--text-3);">{{ $e->ncc ? 'NCC ' . $e->ncc : ($e->rccm ?: '—') }}</div></td>
                    <td style="font-size:12.5px; white-space:nowrap;">{{ $e->corbeille_le?->format('d/m/Y H:i') }}</td>
                    <td>{{ $e->utilisateurs_count }}</td>
                    <td style="white-space:nowrap;">
                        <span style="font-weight:700; color:{{ $jours <= 3 ? '#b91c1c' : 'var(--text-2)' }};">
                            {{ $jours === 0 ? 'imminent' : $jours . ' jour' . ($jours > 1 ? 's' : '') }}
                        </span>
                    </td>
                    <td style="text-align:right; white-space:nowrap;">
                        <form method="POST" action="{{ route('superadmin.corbeille.restaurer', $e) }}" style="display:inline; margin:0;">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-rotate-left"></i> Restaurer</button>
                        </form>
                        <form method="POST" action="{{ route('superadmin.corbeille.vider_une', $e) }}" style="display:inline; margin:0;"
                              onsubmit="return confirm('Effacer définitivement « {{ addslashes($e->nom) }} » ? Cette action est sans retour.');">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm" style="background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5;"><i class="fas fa-xmark"></i> Effacer</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div style="padding:12px 16px;">{{ $entreprises->links() }}</div>
        @endif
    </div>
</div>
@endsection
