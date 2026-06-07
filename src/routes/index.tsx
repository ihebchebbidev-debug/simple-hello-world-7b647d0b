import { createFileRoute } from "@tanstack/react-router";
import { useState, useCallback } from "react";
import { motion, AnimatePresence } from "motion/react";

export const Route = createFileRoute("/")({
  head: () => ({
    meta: [
      { title: "Hello World Word App" },
      { name: "description", content: "A simple, beautiful word display app. Type and see your words come alive." },
      { property: "og:title", content: "Hello World Word App" },
      { property: "og:description", content: "A simple, beautiful word display app." },
    ],
  }),
  component: Index,
});

function Index() {
  const [text, setText] = useState("Hello World");
  const words = text.trim().split(/\s+/).filter(Boolean);

  const handleClear = useCallback(() => {
    setText("");
  }, []);

  const handleReset = useCallback(() => {
    setText("Hello World");
  }, []);

  return (
    <main className="flex min-h-screen flex-col items-center justify-center gap-12 px-6 py-16">
      <div className="flex w-full max-w-2xl flex-col gap-6">
        <label htmlFor="word-input" className="text-sm font-medium text-muted-foreground tracking-wide uppercase">
          Type something
        </label>
        <textarea
          id="word-input"
          value={text}
          onChange={(e) => setText(e.target.value)}
          placeholder="Type your words here..."
          rows={3}
          className="w-full resize-none rounded-2xl border border-input bg-card px-5 py-4 text-lg leading-relaxed text-foreground shadow-sm transition-all focus:border-ring focus:ring-2 focus:ring-ring/20 focus:outline-none"
        />
        <div className="flex items-center gap-3">
          <button
            onClick={handleClear}
            className="rounded-full border border-input bg-background px-5 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-muted"
          >
            Clear
          </button>
          <button
            onClick={handleReset}
            className="rounded-full bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90"
          >
            Reset
          </button>
        </div>
      </div>

      <div className="w-full max-w-4xl">
        <AnimatePresence mode="popLayout">
          {words.length === 0 ? (
            <motion.p
              key="empty"
              initial={{ opacity: 0, y: 10 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0, y: -10 }}
              className="text-center text-muted-foreground"
            >
              Start typing to see your words...
            </motion.p>
          ) : (
            <motion.div
              key="words"
              className="flex flex-wrap justify-center gap-3"
              layout
            >
              {words.map((word, i) => (
                <motion.span
                  key={`${word}-${i}`}
                  layout
                  initial={{ opacity: 0, scale: 0.7, y: 20 }}
                  animate={{ opacity: 1, scale: 1, y: 0 }}
                  exit={{ opacity: 0, scale: 0.7 }}
                  transition={{
                    type: "spring",
                    stiffness: 400,
                    damping: 25,
                    delay: i * 0.03,
                  }}
                  className="inline-flex items-center rounded-xl bg-card px-5 py-3 text-2xl font-semibold text-foreground shadow-sm border border-border/50 hover:border-primary/40 hover:shadow-md transition-shadow"
                  style={{
                    fontFamily: "'DM Serif Display', Georgia, serif",
                  }}
                >
                  {word}
                </motion.span>
              ))}
            </motion.div>
          )}
        </AnimatePresence>
      </div>

      <div className="flex items-center gap-8 text-sm text-muted-foreground">
        <span>{words.length} word{words.length !== 1 ? "s" : ""}</span>
        <span className="h-1 w-1 rounded-full bg-muted-foreground/40" />
        <span>{text.length} character{text.length !== 1 ? "s" : ""}</span>
      </div>
    </main>
  );
}
