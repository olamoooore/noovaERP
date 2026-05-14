import { useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, Heart, Play } from 'lucide-react';
import { Button } from '@/storefront/components/ui/button';
import { cn } from '@/lib/utils';

type Drop = {
  id: number;
  x: number;
  y: number;
  kind: 'card' | 'star' | 'bomb';
};

const FIELD_HEIGHT = 480;
const BASKET_WIDTH = 88;

export function CatchGame() {
  const [running, setRunning] = useState(false);
  const [basketX, setBasketX] = useState(50);
  const [drops, setDrops] = useState<Drop[]>([]);
  const [score, setScore] = useState(0);
  const [lives, setLives] = useState(3);
  const [level, setLevel] = useState(1);
  const dropIdRef = useRef(0);
  const fieldRef = useRef<HTMLDivElement>(null);
  const moveRef = useRef<'left' | 'right' | null>(null);

  useEffect(() => {
    if (!running) return;

    let lastTime = performance.now();
    let spawnAccum = 0;
    let rafId = 0;

    const tick = (now: number) => {
      const delta = Math.min(now - lastTime, 50);
      lastTime = now;
      spawnAccum += delta;

      const spawnInterval = Math.max(500, 1100 - level * 90);
      if (spawnAccum >= spawnInterval) {
        spawnAccum = 0;
        const roll = Math.random();
        const kind: Drop['kind'] = roll < 0.18 ? 'bomb' : roll < 0.32 ? 'star' : 'card';
        dropIdRef.current += 1;
        setDrops((current) => [
          ...current,
          { id: dropIdRef.current, x: 8 + Math.random() * 84, y: -32, kind },
        ]);
      }

      if (moveRef.current === 'left') setBasketX((value) => Math.max(6, value - 1.6));
      if (moveRef.current === 'right') setBasketX((value) => Math.min(94, value + 1.6));

      setDrops((current) => {
        const fallSpeed = 3 + level * 0.6;
        const advanced = current.map((drop) => ({ ...drop, y: drop.y + fallSpeed * (delta / 16) }));

        const remaining: Drop[] = [];
        let scoreDelta = 0;
        let lifeDelta = 0;

        advanced.forEach((drop) => {
          if (drop.y < FIELD_HEIGHT - 70) {
            remaining.push(drop);
            return;
          }
          if (drop.y > FIELD_HEIGHT - 32 && drop.y < FIELD_HEIGHT - 8) {
            const basketLeft = basketX - (BASKET_WIDTH / 2) / 4;
            const basketRight = basketX + (BASKET_WIDTH / 2) / 4;
            if (drop.x >= basketLeft && drop.x <= basketRight) {
              if (drop.kind === 'card') scoreDelta += 10;
              if (drop.kind === 'star') scoreDelta += 30;
              if (drop.kind === 'bomb') lifeDelta -= 1;
              return;
            }
          }
          if (drop.y > FIELD_HEIGHT) {
            if (drop.kind === 'star') lifeDelta -= 0;
            return;
          }
          remaining.push(drop);
        });

        if (scoreDelta !== 0) setScore((value) => value + scoreDelta);
        if (lifeDelta !== 0) setLives((value) => Math.max(0, value + lifeDelta));
        return remaining;
      });

      rafId = window.requestAnimationFrame(tick);
    };

    rafId = window.requestAnimationFrame(tick);
    return () => window.cancelAnimationFrame(rafId);
  }, [running, level, basketX]);

  useEffect(() => {
    if (running && lives <= 0) setRunning(false);
  }, [lives, running]);

  useEffect(() => {
    if (!running) return;
    setLevel(1 + Math.floor(score / 120));
  }, [score, running]);

  useEffect(() => {
    if (!running) return;
    const handleDown = (event: KeyboardEvent) => {
      if (event.key === 'ArrowLeft') moveRef.current = 'left';
      if (event.key === 'ArrowRight') moveRef.current = 'right';
    };
    const handleUp = (event: KeyboardEvent) => {
      if ((event.key === 'ArrowLeft' && moveRef.current === 'left') || (event.key === 'ArrowRight' && moveRef.current === 'right')) {
        moveRef.current = null;
      }
    };
    window.addEventListener('keydown', handleDown);
    window.addEventListener('keyup', handleUp);
    return () => {
      window.removeEventListener('keydown', handleDown);
      window.removeEventListener('keyup', handleUp);
    };
  }, [running]);

  const handlePointer = (event: React.PointerEvent<HTMLDivElement>) => {
    if (!running) return;
    const field = fieldRef.current;
    if (!field) return;
    const rect = field.getBoundingClientRect();
    const relative = ((event.clientX - rect.left) / rect.width) * 100;
    setBasketX(Math.min(94, Math.max(6, relative)));
  };

  const start = () => {
    setScore(0);
    setLives(3);
    setLevel(1);
    setDrops([]);
    setBasketX(50);
    setRunning(true);
  };

  const gameOver = !running && lives <= 0;

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-3 gap-2 sm:gap-3">
        <Stat label="Score" value={score} />
        <Stat label="Lives" value={'❤'.repeat(lives) || '—'} />
        <Stat label="Level" value={level} />
      </div>

      <div
        ref={fieldRef}
        onPointerMove={handlePointer}
        onPointerDown={handlePointer}
        className="relative w-full overflow-hidden rounded-3xl border border-border/60 bg-gradient-to-b from-indigo-500/10 via-purple-500/5 to-background touch-none"
        style={{ height: FIELD_HEIGHT }}
      >
        {drops.map((drop) => (
          <div
            key={drop.id}
            className={cn(
              'absolute flex items-center justify-center rounded-full font-bold shadow-lg',
              drop.kind === 'card' && 'bg-gradient-to-br from-pink-500 to-fuchsia-500 text-white h-10 w-10 text-lg',
              drop.kind === 'star' && 'bg-gradient-to-br from-amber-400 to-orange-500 text-white h-12 w-12 text-2xl',
              drop.kind === 'bomb' && 'bg-gradient-to-br from-zinc-800 to-zinc-950 text-white h-10 w-10 text-lg'
            )}
            style={{ left: `${drop.x}%`, top: drop.y, transform: 'translateX(-50%)' }}
          >
            {drop.kind === 'card' ? '★' : drop.kind === 'star' ? '✦' : '✖'}
          </div>
        ))}

        <div
          className="absolute bottom-3 h-12 rounded-2xl border-2 border-primary bg-gradient-to-b from-primary/60 to-primary shadow-[0_0_30px_rgba(99,102,241,0.5)] flex items-center justify-center text-primary-foreground font-bold"
          style={{ left: `${basketX}%`, width: BASKET_WIDTH, transform: 'translateX(-50%)' }}
        >
          <Heart className="h-5 w-5" />
        </div>

        {!running && (
          <div className="absolute inset-0 flex items-center justify-center bg-background/80 backdrop-blur-sm">
            <div className="text-center max-w-xs px-6">
              <h3 className="text-2xl font-display font-bold mb-2">
                {gameOver ? 'Game Over' : 'Idol Catch'}
              </h3>
              <p className="text-sm text-muted-foreground mb-4">
                {gameOver
                  ? `Final score ${score} · reached level ${level}`
                  : 'Catch photocards and stars. Avoid bombs. Drag the field or use the buttons.'}
              </p>
              <Button variant="neon" onClick={start}>
                <Play className="mr-2 h-4 w-4" />
                {gameOver ? 'Play Again' : 'Start'}
              </Button>
            </div>
          </div>
        )}
      </div>

      <div className="grid grid-cols-2 gap-3 sm:hidden">
        <button
          type="button"
          onPointerDown={() => { moveRef.current = 'left'; }}
          onPointerUp={() => { moveRef.current = null; }}
          onPointerLeave={() => { moveRef.current = null; }}
          className="h-16 rounded-2xl border border-border/60 bg-card flex items-center justify-center active:scale-95 transition-transform"
          aria-label="Move left"
        >
          <ChevronLeft className="h-7 w-7" />
        </button>
        <button
          type="button"
          onPointerDown={() => { moveRef.current = 'right'; }}
          onPointerUp={() => { moveRef.current = null; }}
          onPointerLeave={() => { moveRef.current = null; }}
          className="h-16 rounded-2xl border border-border/60 bg-card flex items-center justify-center active:scale-95 transition-transform"
          aria-label="Move right"
        >
          <ChevronRight className="h-7 w-7" />
        </button>
      </div>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="rounded-xl border border-border/60 bg-card px-2 py-2 sm:px-3 sm:py-2.5 text-center">
      <p className="text-[9px] sm:text-[10px] font-semibold uppercase tracking-[0.16em] text-muted-foreground">{label}</p>
      <p className="mt-0.5 sm:mt-1 text-base sm:text-lg font-display font-bold text-foreground">{value}</p>
    </div>
  );
}
