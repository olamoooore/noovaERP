import { useEffect, useMemo, useState } from 'react';
import { RotateCcw, Trophy } from 'lucide-react';
import { Button } from '@/storefront/components/ui/button';
import { cn } from '@/lib/utils';

type Card = {
  id: string;
  symbol: string;
  color: string;
  matched: boolean;
};

const SYMBOL_POOL: Array<{ symbol: string; color: string }> = [
  { symbol: '★', color: 'from-pink-500 to-rose-500' },
  { symbol: '♡', color: 'from-fuchsia-500 to-purple-500' },
  { symbol: '◆', color: 'from-violet-500 to-indigo-500' },
  { symbol: '♪', color: 'from-blue-500 to-cyan-500' },
  { symbol: '✿', color: 'from-emerald-500 to-teal-500' },
  { symbol: '☀', color: 'from-amber-500 to-orange-500' },
  { symbol: '⚡', color: 'from-yellow-400 to-amber-500' },
  { symbol: '☾', color: 'from-sky-500 to-blue-600' },
];

function shuffle<T>(items: T[]): T[] {
  const shuffled = [...items];
  for (let index = shuffled.length - 1; index > 0; index -= 1) {
    const swap = Math.floor(Math.random() * (index + 1));
    [shuffled[index], shuffled[swap]] = [shuffled[swap], shuffled[index]];
  }
  return shuffled;
}

function buildDeck(pairs: number): Card[] {
  const slice = SYMBOL_POOL.slice(0, pairs);
  const doubled = slice.flatMap((entry) => [entry, entry]);
  return shuffle(doubled.map((entry, index) => ({
    id: `${entry.symbol}-${index}-${Math.random().toString(16).slice(2)}`,
    symbol: entry.symbol,
    color: entry.color,
    matched: false,
  })));
}

export function MemoryGame() {
  const [pairs] = useState(8);
  const [cards, setCards] = useState<Card[]>(() => buildDeck(8));
  const [openIds, setOpenIds] = useState<string[]>([]);
  const [moves, setMoves] = useState(0);
  const [seconds, setSeconds] = useState(0);
  const [running, setRunning] = useState(false);

  const matched = useMemo(() => cards.filter((card) => card.matched).length / 2, [cards]);
  const complete = matched === pairs;

  useEffect(() => {
    if (!running || complete) return;
    const timerId = window.setInterval(() => setSeconds((value) => value + 1), 1000);
    return () => window.clearInterval(timerId);
  }, [running, complete]);

  useEffect(() => {
    if (openIds.length !== 2) return;
    const [firstId, secondId] = openIds;
    const firstCard = cards.find((card) => card.id === firstId);
    const secondCard = cards.find((card) => card.id === secondId);
    if (!firstCard || !secondCard) return;

    setMoves((value) => value + 1);

    if (firstCard.symbol === secondCard.symbol) {
      setCards((current) => current.map((card) => (
        openIds.includes(card.id) ? { ...card, matched: true } : card
      )));
      setOpenIds([]);
      return;
    }

    const timeoutId = window.setTimeout(() => setOpenIds([]), 700);
    return () => window.clearTimeout(timeoutId);
  }, [cards, openIds]);

  const flip = (cardId: string) => {
    if (!running) setRunning(true);
    if (openIds.length === 2) return;
    if (openIds.includes(cardId)) return;
    setOpenIds((current) => [...current, cardId]);
  };

  const reset = () => {
    setCards(buildDeck(pairs));
    setOpenIds([]);
    setMoves(0);
    setSeconds(0);
    setRunning(false);
  };

  return (
    <div className="space-y-4 sm:space-y-6">
      <div className="grid grid-cols-4 gap-2 sm:gap-3">
        <Stat label="Pairs" value={`${matched}/${pairs}`} />
        <Stat label="Moves" value={moves} />
        <Stat label="Time" value={`${seconds}s`} />
        <div className="flex items-center justify-end">
          <Button variant="outline" size="sm" onClick={reset} className="w-full sm:w-auto">
            <RotateCcw className="mr-1.5 h-4 w-4" />
            Reset
          </Button>
        </div>
      </div>

      <div className="grid grid-cols-4 gap-2 sm:gap-3">
        {cards.map((card) => {
          const isOpen = card.matched || openIds.includes(card.id);

          return (
            <button
              key={card.id}
              type="button"
              onClick={() => flip(card.id)}
              className="aspect-[3/4] [perspective:1000px] focus:outline-none"
              aria-label={isOpen ? `Card ${card.symbol}` : 'Hidden card'}
            >
              <div
                className={cn(
                  'relative h-full w-full transition-transform duration-500 [transform-style:preserve-3d]',
                  isOpen && '[transform:rotateY(180deg)]'
                )}
              >
                <div className="absolute inset-0 rounded-2xl border border-border/60 bg-gradient-to-br from-card to-muted [backface-visibility:hidden] flex items-center justify-center shadow-sm">
                  <div className="h-10 w-10 rounded-full bg-primary/20 flex items-center justify-center text-primary font-display font-black">
                    ?
                  </div>
                </div>
                <div className={cn(
                  'absolute inset-0 rounded-2xl border [transform:rotateY(180deg)] [backface-visibility:hidden] flex items-center justify-center text-white text-3xl sm:text-5xl font-black shadow-lg bg-gradient-to-br',
                  card.color,
                  card.matched ? 'opacity-80' : ''
                )}>
                  {card.symbol}
                </div>
              </div>
            </button>
          );
        })}
      </div>

      {complete && (
        <div className="rounded-2xl border border-primary/40 bg-primary/10 p-4 sm:p-5 text-center">
          <Trophy className="mx-auto h-7 w-7 text-primary mb-2" />
          <p className="font-display font-bold text-foreground text-lg">Cleared in {moves} moves · {seconds}s</p>
          <p className="text-sm text-muted-foreground mt-1">Hit reset to chase a better score.</p>
        </div>
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
