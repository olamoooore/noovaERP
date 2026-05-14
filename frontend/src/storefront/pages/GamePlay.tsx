import { useEffect } from 'react';
import { Link, Navigate, useParams } from 'react-router-dom';
import { ArrowLeft, Gamepad2 } from 'lucide-react';
import { Layout } from '@/storefront/components/layout/Layout';
import { Badge } from '@/storefront/components/ui/badge';
import { storefrontRoutes } from '@/storefront/lib/paths';
import { MemoryGame } from '@/storefront/pages/games/MemoryGame';
import { BeatTapGame } from '@/storefront/pages/games/BeatTapGame';
import { CatchGame } from '@/storefront/pages/games/CatchGame';
import { QuizGame } from '@/storefront/pages/games/QuizGame';

type GameId = 'memory' | 'beat' | 'catch' | 'quiz';

const META: Record<GameId, { title: string; tagline: string; how: string; accent: string }> = {
  memory: {
    title: 'Photocard Match',
    tagline: 'Memory · Pairs',
    how: 'Tap a card to flip it. Match all pairs in as few moves as possible.',
    accent: 'from-pink-500 via-rose-500 to-fuchsia-600',
  },
  beat: {
    title: 'Beat Tap',
    tagline: 'Rhythm · 4 Lanes',
    how: 'Tap each lane button (or press A / S / D / F) when a note crosses the glow zone.',
    accent: 'from-violet-500 via-indigo-500 to-cyan-500',
  },
  catch: {
    title: 'Idol Catch',
    tagline: 'Arcade · Catcher',
    how: 'Drag the field or use the arrow buttons. Catch photocards and stars, avoid bombs.',
    accent: 'from-amber-400 via-orange-500 to-rose-500',
  },
  quiz: {
    title: 'Lyric Quiz',
    tagline: 'Trivia · Timed',
    how: 'Pick the right answer before the timer runs out. Speed and streaks boost your score.',
    accent: 'from-emerald-400 via-teal-500 to-cyan-500',
  },
};

function isGameId(value: string | undefined): value is GameId {
  return value === 'memory' || value === 'beat' || value === 'catch' || value === 'quiz';
}

export default function GamePlay() {
  const { gameId } = useParams<{ gameId: string }>();

  useEffect(() => {
    document.body.classList.add('overflow-x-hidden');
    return () => document.body.classList.remove('overflow-x-hidden');
  }, []);

  if (!isGameId(gameId)) {
    return <Navigate to={storefrontRoutes.games} replace />;
  }

  const meta = META[gameId];

  return (
    <Layout>
      <div className="relative min-h-screen bg-gradient-to-b from-background via-background to-muted/40">
        <div className={`absolute inset-x-0 top-0 h-48 sm:h-64 bg-gradient-to-br ${meta.accent} opacity-20 pointer-events-none`} />

        <div className="relative container mx-auto px-4 pt-6 pb-12 max-w-3xl">
          <div className="flex items-center justify-between mb-5">
            <Link
              to={storefrontRoutes.games}
              className="inline-flex items-center gap-1.5 text-sm font-semibold text-muted-foreground hover:text-foreground"
            >
              <ArrowLeft className="h-4 w-4" />
              Arcade
            </Link>
            <Badge variant="outline" className="gap-1.5 bg-card/60 backdrop-blur">
              <Gamepad2 className="h-3.5 w-3.5" />
              Now Playing
            </Badge>
          </div>

          <header className="mb-6 sm:mb-8">
            <p className="text-xs font-bold uppercase tracking-[0.2em] text-muted-foreground">{meta.tagline}</p>
            <h1 className="mt-1 text-3xl sm:text-5xl font-display font-black leading-tight">{meta.title}</h1>
            <p className="mt-2 text-sm text-muted-foreground max-w-xl">{meta.how}</p>
          </header>

          <div className="rounded-3xl border border-border/60 bg-card/70 backdrop-blur p-3 sm:p-5">
            {gameId === 'memory' && <MemoryGame />}
            {gameId === 'beat' && <BeatTapGame />}
            {gameId === 'catch' && <CatchGame />}
            {gameId === 'quiz' && <QuizGame />}
          </div>
        </div>
      </div>
    </Layout>
  );
}
