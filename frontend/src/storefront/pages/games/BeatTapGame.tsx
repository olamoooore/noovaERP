import { useEffect, useRef, useState } from 'react';
import { Play, Square } from 'lucide-react';
import { Button } from '@/storefront/components/ui/button';
import { cn } from '@/lib/utils';

type Note = {
  id: number;
  lane: number;
  y: number;
};

const LANES = [
  { label: 'A', color: 'from-pink-500 to-rose-500', glow: 'shadow-[0_0_30px_rgba(244,114,182,0.6)]' },
  { label: 'S', color: 'from-violet-500 to-purple-500', glow: 'shadow-[0_0_30px_rgba(167,139,250,0.6)]' },
  { label: 'D', color: 'from-cyan-500 to-sky-500', glow: 'shadow-[0_0_30px_rgba(56,189,248,0.6)]' },
  { label: 'F', color: 'from-amber-500 to-orange-500', glow: 'shadow-[0_0_30px_rgba(251,146,60,0.6)]' },
];

const FIELD_HEIGHT = 480;
const HIT_ZONE_HEIGHT = 64;
const HIT_TOLERANCE = 60;

export function BeatTapGame() {
  const [notes, setNotes] = useState<Note[]>([]);
  const [running, setRunning] = useState(false);
  const [score, setScore] = useState(0);
  const [combo, setCombo] = useState(0);
  const [maxCombo, setMaxCombo] = useState(0);
  const [misses, setMisses] = useState(0);
  const [timeLeft, setTimeLeft] = useState(45);
  const [feedback, setFeedback] = useState<string | null>(null);
  const noteIdRef = useRef(0);
  const speedRef = useRef(4);
  const spawnAccumRef = useRef(0);

  useEffect(() => {
    if (!running) return;

    let lastTime = performance.now();
    let rafId = 0;

    const tick = (now: number) => {
      const delta = Math.min(now - lastTime, 50);
      lastTime = now;
      spawnAccumRef.current += delta;

      const spawnInterval = Math.max(420, 900 - score * 1.5);
      if (spawnAccumRef.current >= spawnInterval) {
        spawnAccumRef.current = 0;
        noteIdRef.current += 1;
        setNotes((current) => [
          ...current,
          { id: noteIdRef.current, lane: Math.floor(Math.random() * LANES.length), y: -40 },
        ]);
      }

      setNotes((current) => {
        const advanced = current.map((note) => ({ ...note, y: note.y + speedRef.current * (delta / 16) }));
        const fallen = advanced.filter((note) => note.y > FIELD_HEIGHT + 40);
        if (fallen.length > 0) {
          setMisses((value) => value + fallen.length);
          setCombo(0);
        }
        return advanced.filter((note) => note.y <= FIELD_HEIGHT + 40);
      });

      rafId = window.requestAnimationFrame(tick);
    };

    rafId = window.requestAnimationFrame(tick);
    return () => window.cancelAnimationFrame(rafId);
  }, [running, score]);

  useEffect(() => {
    if (!running) return;
    const clockId = window.setInterval(() => {
      setTimeLeft((value) => {
        if (value <= 1) {
          setRunning(false);
          return 0;
        }
        return value - 1;
      });
    }, 1000);
    return () => window.clearInterval(clockId);
  }, [running]);

  useEffect(() => {
    speedRef.current = 4 + Math.floor(score / 80);
  }, [score]);

  useEffect(() => {
    if (!running) return;
    const handleKey = (event: KeyboardEvent) => {
      const laneIndex = LANES.findIndex((lane) => lane.label.toLowerCase() === event.key.toLowerCase());
      if (laneIndex >= 0) {
        event.preventDefault();
        hitLane(laneIndex);
      }
    };
    window.addEventListener('keydown', handleKey);
    return () => window.removeEventListener('keydown', handleKey);
  });

  const hitLane = (laneIndex: number) => {
    if (!running) return;
    const hitLineY = FIELD_HEIGHT - HIT_ZONE_HEIGHT / 2;
    const candidate = notes
      .filter((note) => note.lane === laneIndex && Math.abs(note.y - hitLineY) <= HIT_TOLERANCE)
      .sort((left, right) => Math.abs(left.y - hitLineY) - Math.abs(right.y - hitLineY))[0];

    if (!candidate) {
      setCombo(0);
      setFeedback('miss');
      window.setTimeout(() => setFeedback(null), 250);
      return;
    }

    setNotes((current) => current.filter((note) => note.id !== candidate.id));
    const distance = Math.abs(candidate.y - hitLineY);
    const points = distance < 18 ? 25 : distance < 35 ? 15 : 8;
    setScore((value) => value + points + combo);
    setCombo((value) => {
      const next = value + 1;
      setMaxCombo((current) => Math.max(current, next));
      return next;
    });
    setFeedback(distance < 18 ? 'perfect' : distance < 35 ? 'great' : 'good');
    window.setTimeout(() => setFeedback(null), 250);
  };

  const start = () => {
    setNotes([]);
    setScore(0);
    setCombo(0);
    setMaxCombo(0);
    setMisses(0);
    setTimeLeft(45);
    spawnAccumRef.current = 0;
    speedRef.current = 4;
    setRunning(true);
  };

  const stop = () => setRunning(false);
  const gameOver = !running && timeLeft === 0;

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-4 gap-2 sm:gap-3">
        <Stat label="Score" value={score} />
        <Stat label="Combo" value={`x${combo}`} />
        <Stat label="Best" value={`x${maxCombo}`} />
        <Stat label="Time" value={`${timeLeft}s`} />
      </div>

      <div
        className="relative w-full overflow-hidden rounded-3xl border border-border/60 bg-gradient-to-b from-background via-card to-muted/40"
        style={{ height: FIELD_HEIGHT }}
      >
        {LANES.map((_, laneIndex) => (
          <div
            key={laneIndex}
            className="absolute top-0 bottom-0 border-r border-border/30 last:border-r-0"
            style={{ left: `${(laneIndex / LANES.length) * 100}%`, width: `${100 / LANES.length}%` }}
          />
        ))}

        <div
          className="absolute left-0 right-0 border-y-2 border-primary/60 bg-primary/10"
          style={{ bottom: 0, height: HIT_ZONE_HEIGHT }}
        />

        {notes.map((note) => (
          <div
            key={note.id}
            className={cn(
              'absolute rounded-xl bg-gradient-to-br shadow-lg',
              LANES[note.lane].color
            )}
            style={{
              left: `${(note.lane / LANES.length) * 100 + 100 / LANES.length / 2}%`,
              top: note.y,
              width: `calc(${100 / LANES.length}% - 16px)`,
              height: 44,
              transform: 'translateX(-50%)',
            }}
          />
        ))}

        {feedback && (
          <div className="absolute inset-x-0 top-4 flex justify-center pointer-events-none">
            <span className={cn(
              'rounded-full px-4 py-1 text-sm font-bold uppercase tracking-wider',
              feedback === 'perfect' && 'bg-emerald-500 text-white',
              feedback === 'great' && 'bg-primary text-primary-foreground',
              feedback === 'good' && 'bg-amber-500 text-white',
              feedback === 'miss' && 'bg-rose-500 text-white'
            )}>
              {feedback}
            </span>
          </div>
        )}

        {!running && (
          <div className="absolute inset-0 flex items-center justify-center bg-background/80 backdrop-blur-sm">
            <div className="text-center max-w-xs px-6">
              <h3 className="text-2xl font-display font-bold mb-2">
                {gameOver ? 'Round Complete' : 'Beat Tap'}
              </h3>
              <p className="text-sm text-muted-foreground mb-4">
                {gameOver
                  ? `Score ${score} · Best combo x${maxCombo} · ${misses} miss`
                  : 'Tap the lane when the note hits the glowing zone at the bottom.'}
              </p>
              <Button variant="neon" onClick={start}>
                <Play className="mr-2 h-4 w-4" />
                {gameOver ? 'Play Again' : 'Start'}
              </Button>
            </div>
          </div>
        )}
      </div>

      <div className="grid grid-cols-4 gap-2 sm:gap-3">
        {LANES.map((lane, laneIndex) => (
          <button
            key={lane.label}
            type="button"
            onPointerDown={(event) => { event.preventDefault(); hitLane(laneIndex); }}
            className={cn(
              'h-16 sm:h-20 rounded-2xl border border-border/60 bg-gradient-to-br text-white font-display font-black text-xl active:scale-95 transition-transform touch-manipulation',
              lane.color,
              lane.glow
            )}
            aria-label={`Tap lane ${lane.label}`}
          >
            {lane.label}
          </button>
        ))}
      </div>

      {running && (
        <Button variant="outline" size="sm" onClick={stop}>
          <Square className="mr-2 h-4 w-4" />
          End Round
        </Button>
      )}
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
