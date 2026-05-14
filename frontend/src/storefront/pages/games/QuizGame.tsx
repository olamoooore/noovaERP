import { useEffect, useMemo, useState } from 'react';
import { Check, Play, RotateCcw, Timer, Trophy, X } from 'lucide-react';
import { Button } from '@/storefront/components/ui/button';
import { cn } from '@/lib/utils';

type Question = {
  prompt: string;
  choices: string[];
  answer: number;
};

const QUESTION_POOL: Question[] = [
  { prompt: 'Which group is known for the song "Dynamite"?', choices: ['BTS', 'EXO', 'NCT', 'TXT'], answer: 0 },
  { prompt: 'How many members are in BLACKPINK?', choices: ['3', '4', '5', '6'], answer: 1 },
  { prompt: 'Which company manages aespa?', choices: ['JYP', 'HYBE', 'SM', 'YG'], answer: 2 },
  { prompt: '"Tomboy" is a hit single by which group?', choices: ['IVE', '(G)I-DLE', 'ITZY', 'LE SSERAFIM'], answer: 1 },
  { prompt: 'NewJeans is under which label?', choices: ['ADOR', 'Source Music', 'BigHit', 'Pledis'], answer: 0 },
  { prompt: 'Which member is in Stray Kids?', choices: ['Felix', 'Jeno', 'Mark Lee', 'Wonpil'], answer: 0 },
  { prompt: 'Lightstick "Bong Bong" belongs to?', choices: ['SEVENTEEN', 'TWICE', 'BTS', 'ATEEZ'], answer: 0 },
  { prompt: '"After LIKE" is by which group?', choices: ['IVE', 'NMIXX', 'STAYC', 'Kep1er'], answer: 0 },
  { prompt: 'TXT debuted in which year?', choices: ['2017', '2018', '2019', '2020'], answer: 2 },
  { prompt: 'Which is NOT a 4th-gen group?', choices: ['IVE', 'TWICE', 'LE SSERAFIM', 'NewJeans'], answer: 1 },
  { prompt: '"God\'s Menu" is a song by?', choices: ['ATEEZ', 'Stray Kids', 'ENHYPEN', 'TXT'], answer: 1 },
  { prompt: 'Which group has the song "FEARLESS"?', choices: ['LE SSERAFIM', 'IVE', 'aespa', 'ITZY'], answer: 0 },
];

const ROUND_LENGTH = 8;
const QUESTION_TIME = 15;

function shuffle<T>(items: T[]): T[] {
  const shuffled = [...items];
  for (let index = shuffled.length - 1; index > 0; index -= 1) {
    const swap = Math.floor(Math.random() * (index + 1));
    [shuffled[index], shuffled[swap]] = [shuffled[swap], shuffled[index]];
  }
  return shuffled;
}

export function QuizGame() {
  const [running, setRunning] = useState(false);
  const [questions, setQuestions] = useState<Question[]>([]);
  const [questionIndex, setQuestionIndex] = useState(0);
  const [score, setScore] = useState(0);
  const [streak, setStreak] = useState(0);
  const [selected, setSelected] = useState<number | null>(null);
  const [timeLeft, setTimeLeft] = useState(QUESTION_TIME);
  const [revealed, setRevealed] = useState(false);

  const current = questions[questionIndex];
  const finished = running === false && questions.length > 0 && questionIndex >= questions.length;
  const totalRounds = useMemo(() => questions.length, [questions]);

  useEffect(() => {
    if (!running || revealed) return;
    if (timeLeft <= 0) {
      setRevealed(true);
      setStreak(0);
      const timeoutId = window.setTimeout(advance, 900);
      return () => window.clearTimeout(timeoutId);
    }
    const tickId = window.setTimeout(() => setTimeLeft((value) => value - 1), 1000);
    return () => window.clearTimeout(tickId);
  });

  const start = () => {
    setQuestions(shuffle(QUESTION_POOL).slice(0, ROUND_LENGTH));
    setQuestionIndex(0);
    setScore(0);
    setStreak(0);
    setSelected(null);
    setRevealed(false);
    setTimeLeft(QUESTION_TIME);
    setRunning(true);
  };

  const advance = () => {
    setSelected(null);
    setRevealed(false);
    setTimeLeft(QUESTION_TIME);
    setQuestionIndex((value) => {
      const next = value + 1;
      if (next >= ROUND_LENGTH) {
        setRunning(false);
      }
      return next;
    });
  };

  const pick = (choiceIndex: number) => {
    if (!running || revealed || !current) return;
    setSelected(choiceIndex);
    setRevealed(true);
    if (choiceIndex === current.answer) {
      const points = 10 + Math.max(0, timeLeft) + streak * 2;
      setScore((value) => value + points);
      setStreak((value) => value + 1);
    } else {
      setStreak(0);
    }
    window.setTimeout(advance, 900);
  };

  if (!running && !finished) {
    return (
      <div className="rounded-3xl border border-border/60 bg-gradient-to-br from-amber-500/10 via-card to-orange-500/5 p-8 text-center space-y-4">
        <Trophy className="mx-auto h-10 w-10 text-amber-500" />
        <div>
          <h3 className="text-2xl font-display font-bold">Lyric Quiz</h3>
          <p className="text-sm text-muted-foreground mt-1">8 questions · {QUESTION_TIME}s each · faster answers score more.</p>
        </div>
        <Button variant="neon" onClick={start}>
          <Play className="mr-2 h-4 w-4" />
          Start Quiz
        </Button>
      </div>
    );
  }

  if (finished) {
    return (
      <div className="rounded-3xl border border-primary/40 bg-primary/10 p-8 text-center space-y-4">
        <Trophy className="mx-auto h-10 w-10 text-primary" />
        <div>
          <h3 className="text-2xl font-display font-bold">Quiz Complete</h3>
          <p className="text-sm text-muted-foreground mt-1">Final score {score}</p>
        </div>
        <Button variant="neon" onClick={start}>
          <RotateCcw className="mr-2 h-4 w-4" />
          Play Again
        </Button>
      </div>
    );
  }

  if (!current) return null;

  return (
    <div className="space-y-5">
      <div className="grid grid-cols-4 gap-2 sm:gap-3">
        <Stat label="Q" value={`${questionIndex + 1}/${totalRounds}`} />
        <Stat label="Score" value={score} />
        <Stat label="Streak" value={`x${streak}`} />
        <Stat label="Time" value={`${timeLeft}s`} />
      </div>

      <div className="rounded-3xl border border-border/60 bg-card p-5 sm:p-7">
        <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-muted-foreground mb-3">
          <Timer className="h-3.5 w-3.5" />
          {timeLeft}s left
        </div>
        <h3 className="text-xl sm:text-2xl font-display font-bold leading-snug">{current.prompt}</h3>

        <div className="mt-5 grid gap-3 sm:grid-cols-2">
          {current.choices.map((choice, choiceIndex) => {
            const isCorrect = revealed && choiceIndex === current.answer;
            const isWrongPick = revealed && selected === choiceIndex && choiceIndex !== current.answer;

            return (
              <button
                key={choice}
                type="button"
                onClick={() => pick(choiceIndex)}
                disabled={revealed}
                className={cn(
                  'flex items-center justify-between gap-3 rounded-2xl border p-4 text-left text-base font-semibold transition-all',
                  isCorrect && 'border-emerald-500 bg-emerald-500/15 text-foreground',
                  isWrongPick && 'border-rose-500 bg-rose-500/15 text-foreground',
                  !revealed && 'border-border/60 bg-background hover:border-primary/40 hover:-translate-y-0.5'
                )}
              >
                <span>{choice}</span>
                {isCorrect && <Check className="h-5 w-5 text-emerald-500" />}
                {isWrongPick && <X className="h-5 w-5 text-rose-500" />}
              </button>
            );
          })}
        </div>
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
