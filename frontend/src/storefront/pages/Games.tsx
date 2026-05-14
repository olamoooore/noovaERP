import { Link } from 'react-router-dom';
import {
  ArrowRight,
  Brain,
  Crown,
  Gamepad2,
  HandMetal,
  Heart,
  Music2,
  Sparkles,
  Star,
  Trophy,
  Zap,
} from 'lucide-react';
import { Layout } from '@/storefront/components/layout/Layout';
import { Button } from '@/storefront/components/ui/button';
import { Badge } from '@/storefront/components/ui/badge';
import { storefrontRoutes, gamePlayRoute } from '@/storefront/lib/paths';
import { cn } from '@/lib/utils';

type GameTile = {
  id: 'memory' | 'beat' | 'catch' | 'quiz';
  title: string;
  tagline: string;
  description: string;
  difficulty: 'Casual' | 'Reflex' | 'Skill' | 'Trivia';
  duration: string;
  icon: typeof Gamepad2;
  accent: string;
  ring: string;
};

const games: GameTile[] = [
  {
    id: 'memory',
    title: 'Photocard Match',
    tagline: 'Memory · Pairs',
    description: 'Flip cards and clear the board. Fewer moves and quicker times rule the leaderboard.',
    difficulty: 'Casual',
    duration: '~2 min',
    icon: Sparkles,
    accent: 'from-pink-500 via-rose-500 to-fuchsia-600',
    ring: 'shadow-[0_20px_60px_-20px_rgba(236,72,153,0.6)]',
  },
  {
    id: 'beat',
    title: 'Beat Tap',
    tagline: 'Rhythm · 4 Lanes',
    description: 'Hit the lane the moment the note crosses the glow zone. Build combos for higher scores.',
    difficulty: 'Reflex',
    duration: '45 sec',
    icon: Music2,
    accent: 'from-violet-500 via-indigo-500 to-cyan-500',
    ring: 'shadow-[0_20px_60px_-20px_rgba(139,92,246,0.6)]',
  },
  {
    id: 'catch',
    title: 'Idol Catch',
    tagline: 'Arcade · Catcher',
    description: 'Move the basket, catch photocards and stars, dodge the bombs. Level up as you score.',
    difficulty: 'Skill',
    duration: 'Endless',
    icon: HandMetal,
    accent: 'from-amber-400 via-orange-500 to-rose-500',
    ring: 'shadow-[0_20px_60px_-20px_rgba(251,146,60,0.6)]',
  },
  {
    id: 'quiz',
    title: 'Lyric Quiz',
    tagline: 'Trivia · Timed',
    description: 'Eight rapid-fire K-pop questions. Answer fast, build streaks, climb the leaderboard.',
    difficulty: 'Trivia',
    duration: '~3 min',
    icon: Brain,
    accent: 'from-emerald-400 via-teal-500 to-cyan-500',
    ring: 'shadow-[0_20px_60px_-20px_rgba(16,185,129,0.6)]',
  },
];

const arcadeStats = [
  { label: 'Games', value: '4', icon: Gamepad2 },
  { label: 'Top score', value: '12,480', icon: Trophy },
  { label: 'Players today', value: '8,624', icon: Heart },
  { label: 'Active streaks', value: '231', icon: Zap },
];

export default function Games() {
  return (
    <Layout>
      <div className="relative overflow-hidden bg-gradient-to-b from-background via-background to-muted/40">
        <div className="absolute inset-0 pointer-events-none">
          <div className="absolute -top-32 -left-24 h-72 w-72 rounded-full bg-primary/20 blur-3xl" />
          <div className="absolute top-40 -right-20 h-72 w-72 rounded-full bg-accent/20 blur-3xl" />
        </div>

        <section className="relative container mx-auto px-4 pt-10 sm:pt-16 pb-8 sm:pb-12">
          <div className="max-w-3xl">
            <Badge variant="outline" className="mb-4 gap-1.5 bg-card/60 backdrop-blur">
              <Gamepad2 className="h-3.5 w-3.5" />
              Fan Arcade
            </Badge>
            <h1 className="text-4xl sm:text-6xl font-display font-black leading-[1.05] tracking-tight">
              Play. Score.
              <span className="block bg-gradient-to-r from-primary via-fuchsia-500 to-amber-400 bg-clip-text text-transparent">
                Climb the charts.
              </span>
            </h1>
            <p className="mt-4 text-base sm:text-lg text-muted-foreground max-w-2xl">
              Four arcade-grade mini games built for fans. Pick a game, hit
              <span className="font-semibold text-foreground"> Enter Game</span>, and play in a focused arena built for desktop and mobile.
            </p>
          </div>

          <div className="mt-8 grid grid-cols-2 sm:grid-cols-4 gap-3">
            {arcadeStats.map((stat) => (
              <div
                key={stat.label}
                className="rounded-2xl border border-border/60 bg-card/70 backdrop-blur px-4 py-3 flex items-center gap-3"
              >
                <div className="h-9 w-9 rounded-xl bg-primary/15 flex items-center justify-center">
                  <stat.icon className="h-4 w-4 text-primary" />
                </div>
                <div>
                  <p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">
                    {stat.label}
                  </p>
                  <p className="text-lg font-display font-bold text-foreground leading-none mt-1">{stat.value}</p>
                </div>
              </div>
            ))}
          </div>
        </section>

        <section className="relative container mx-auto px-4 pb-16">
          <div className="flex items-end justify-between mb-5">
            <div>
              <h2 className="text-2xl sm:text-3xl font-display font-bold">Pick your game</h2>
              <p className="text-sm text-muted-foreground">Tap a tile, then press Enter Game to drop into the arena.</p>
            </div>
            <Link
              to={storefrontRoutes.events}
              className="hidden sm:inline-flex items-center gap-1.5 text-sm font-semibold text-muted-foreground hover:text-foreground"
            >
              Back to Events
              <ArrowRight className="h-4 w-4" />
            </Link>
          </div>

          <div className="grid gap-5 sm:gap-6 sm:grid-cols-2">
            {games.map((game) => {
              const Icon = game.icon;
              return (
                <article
                  key={game.id}
                  className={cn(
                    'group relative overflow-hidden rounded-3xl border border-border/60 bg-card transition-all duration-300 hover:-translate-y-1',
                    game.ring
                  )}
                >
                  <div className={cn('relative h-44 sm:h-52 bg-gradient-to-br', game.accent)}>
                    <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(255,255,255,0.35),transparent_55%)]" />
                    <div className="absolute inset-0 flex items-center justify-center">
                      <Icon className="h-20 w-20 sm:h-24 sm:w-24 text-white/95 drop-shadow-[0_8px_24px_rgba(0,0,0,0.35)] group-hover:scale-110 transition-transform" strokeWidth={1.5} />
                    </div>
                    <div className="absolute top-3 left-3 flex items-center gap-1.5">
                      <Badge className="bg-black/40 backdrop-blur text-white border-white/20 gap-1">
                        <Star className="h-3 w-3 fill-current" />
                        {game.difficulty}
                      </Badge>
                      <Badge className="bg-black/40 backdrop-blur text-white border-white/20">
                        {game.duration}
                      </Badge>
                    </div>
                    <div className="absolute top-3 right-3">
                      <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-white/80">
                        {game.tagline}
                      </span>
                    </div>
                  </div>

                  <div className="p-5 sm:p-6 flex flex-col gap-3">
                    <div>
                      <h3 className="text-xl sm:text-2xl font-display font-bold">{game.title}</h3>
                      <p className="text-sm text-muted-foreground mt-1.5">{game.description}</p>
                    </div>

                    <div className="flex items-center justify-between gap-3 pt-2">
                      <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Crown className="h-3.5 w-3.5 text-amber-500" />
                        Beat your best
                      </div>
                      <Button
                        variant="neon"
                        size="sm"
                        asChild
                        className="group/btn"
                      >
                        <Link to={gamePlayRoute(game.id)}>
                          Enter Game
                          <ArrowRight className="ml-1 h-4 w-4 transition-transform group-hover/btn:translate-x-0.5" />
                        </Link>
                      </Button>
                    </div>
                  </div>
                </article>
              );
            })}
          </div>

          <div className="sm:hidden mt-6 flex justify-center">
            <Link
              to={storefrontRoutes.events}
              className="inline-flex items-center gap-1.5 text-sm font-semibold text-muted-foreground"
            >
              Back to Events
              <ArrowRight className="h-4 w-4" />
            </Link>
          </div>
        </section>
      </div>
    </Layout>
  );
}
