/**
 * RichTextEditor – TipTap-based WYSIWYG editor
 * Supports: bold, italic, underline, strike, bullets, numbered lists,
 *           headings, links, images (URL), YouTube embeds, text-align, colours.
 */
import { useCallback, useEffect, useState } from 'react';
import { useEditor, EditorContent, Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import Link from '@tiptap/extension-link';
import TextAlign from '@tiptap/extension-text-align';
import Color from '@tiptap/extension-color';
import { TextStyle } from '@tiptap/extension-text-style';
import Image from '@tiptap/extension-image';
import Youtube from '@tiptap/extension-youtube';
import Placeholder from '@tiptap/extension-placeholder';
import { cn } from '@/lib/utils';
import { PromptDialog } from '@/components/ui/prompt-dialog';
import {
  Bold, Italic, Underline as UnderlineIcon, Strikethrough,
  List, ListOrdered, Heading1, Heading2, Heading3,
  AlignLeft, AlignCenter, AlignRight,
  Link2, ImageIcon, Youtube as YoutubeIcon,
  Undo, Redo, Code, Minus, RotateCcw,
} from 'lucide-react';

/* ── Toolbar button ── */
const ToolBtn = ({
  onClick,
  active = false,
  disabled = false,
  title,
  children,
}: {
  onClick: () => void;
  active?: boolean;
  disabled?: boolean;
  title: string;
  children: React.ReactNode;
}) => (
  <button
    type="button"
    title={title}
    disabled={disabled}
    onMouseDown={(e) => { e.preventDefault(); onClick(); }}
    className={cn(
      'h-7 w-7 rounded flex items-center justify-center text-sm transition-colors',
      'hover:bg-muted focus:outline-none focus:ring-1 focus:ring-primary/30',
      active
        ? 'bg-primary text-primary-foreground hover:bg-primary/90'
        : 'text-foreground/80',
      disabled && 'opacity-30 cursor-not-allowed pointer-events-none',
    )}
  >
    {children}
  </button>
);

const Divider = () => <div className="w-px h-5 bg-border mx-0.5 self-center" />;

/* ── Toolbar ── */
const Toolbar = ({ editor }: { editor: Editor }) => {
  const [promptKind, setPromptKind] = useState<null | 'link' | 'image' | 'youtube'>(null);

  const setLink = useCallback(() => setPromptKind('link'), []);
  const addImage = useCallback(() => setPromptKind('image'), []);
  const addYoutube = useCallback(() => setPromptKind('youtube'), []);

  const handlePromptConfirm = (value: string) => {
    if (promptKind === 'link') {
      if (value === '') {
        editor.chain().focus().unsetLink().run();
      } else {
        editor.chain().focus().setLink({ href: value, target: '_blank' }).run();
      }
    } else if (promptKind === 'image') {
      if (value) editor.chain().focus().setImage({ src: value }).run();
    } else if (promptKind === 'youtube') {
      if (value) editor.commands.setYoutubeVideo({ src: value });
    }
    setPromptKind(null);
  };

  return (
    <>
    <div className="flex flex-wrap items-center gap-0.5 p-2 border-b border-border bg-muted/30 rounded-t-md">
      {/* Undo / Redo */}
      <ToolBtn title="Undo" onClick={() => editor.chain().focus().undo().run()} disabled={!editor.can().undo()}>
        <Undo className="h-3.5 w-3.5" />
      </ToolBtn>
      <ToolBtn title="Redo" onClick={() => editor.chain().focus().redo().run()} disabled={!editor.can().redo()}>
        <Redo className="h-3.5 w-3.5" />
      </ToolBtn>

      <Divider />

      {/* Headings */}
      <ToolBtn title="Heading 1" active={editor.isActive('heading', { level: 1 })}
        onClick={() => editor.chain().focus().toggleHeading({ level: 1 }).run()}>
        <Heading1 className="h-3.5 w-3.5" />
      </ToolBtn>
      <ToolBtn title="Heading 2" active={editor.isActive('heading', { level: 2 })}
        onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}>
        <Heading2 className="h-3.5 w-3.5" />
      </ToolBtn>
      <ToolBtn title="Heading 3" active={editor.isActive('heading', { level: 3 })}
        onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}>
        <Heading3 className="h-3.5 w-3.5" />
      </ToolBtn>

      <Divider />

      {/* Inline styles */}
      <ToolBtn title="Bold" active={editor.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()}>
        <Bold className="h-3.5 w-3.5" />
      </ToolBtn>
      <ToolBtn title="Italic" active={editor.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()}>
        <Italic className="h-3.5 w-3.5" />
      </ToolBtn>
      <ToolBtn title="Underline" active={editor.isActive('underline')} onClick={() => editor.chain().focus().toggleUnderline().run()}>
        <UnderlineIcon className="h-3.5 w-3.5" />
      </ToolBtn>
      <ToolBtn title="Strikethrough" active={editor.isActive('strike')} onClick={() => editor.chain().focus().toggleStrike().run()}>
        <Strikethrough className="h-3.5 w-3.5" />
      </ToolBtn>
      <ToolBtn title="Code" active={editor.isActive('code')} onClick={() => editor.chain().focus().toggleCode().run()}>
        <Code className="h-3.5 w-3.5" />
      </ToolBtn>

      <Divider />

      {/* Lists */}
      <ToolBtn title="Bullet list" active={editor.isActive('bulletList')}
        onClick={() => editor.chain().focus().toggleBulletList().run()}>
        <List className="h-3.5 w-3.5" />
      </ToolBtn>
      <ToolBtn title="Numbered list" active={editor.isActive('orderedList')}
        onClick={() => editor.chain().focus().toggleOrderedList().run()}>
        <ListOrdered className="h-3.5 w-3.5" />
      </ToolBtn>

      <Divider />

      {/* Alignment */}
      <ToolBtn title="Align left" active={editor.isActive({ textAlign: 'left' })}
        onClick={() => editor.chain().focus().setTextAlign('left').run()}>
        <AlignLeft className="h-3.5 w-3.5" />
      </ToolBtn>
      <ToolBtn title="Align center" active={editor.isActive({ textAlign: 'center' })}
        onClick={() => editor.chain().focus().setTextAlign('center').run()}>
        <AlignCenter className="h-3.5 w-3.5" />
      </ToolBtn>
      <ToolBtn title="Align right" active={editor.isActive({ textAlign: 'right' })}
        onClick={() => editor.chain().focus().setTextAlign('right').run()}>
        <AlignRight className="h-3.5 w-3.5" />
      </ToolBtn>

      <Divider />

      {/* Horizontal rule */}
      <ToolBtn title="Horizontal rule" onClick={() => editor.chain().focus().setHorizontalRule().run()}>
        <Minus className="h-3.5 w-3.5" />
      </ToolBtn>

      <Divider />

      {/* Link / Image / Video */}
      <ToolBtn title="Insert / edit link" active={editor.isActive('link')} onClick={setLink}>
        <Link2 className="h-3.5 w-3.5" />
      </ToolBtn>
      <ToolBtn title="Insert image (URL)" onClick={addImage}>
        <ImageIcon className="h-3.5 w-3.5" />
      </ToolBtn>
      <ToolBtn title="Embed YouTube video" onClick={addYoutube}>
        <YoutubeIcon className="h-3.5 w-3.5" />
      </ToolBtn>

      <Divider />

      {/* Clear formatting */}
      <ToolBtn title="Clear formatting" onClick={() => editor.chain().focus().clearNodes().unsetAllMarks().run()}>
        <RotateCcw className="h-3.5 w-3.5" />
      </ToolBtn>
      </div>

      <PromptDialog
        open={promptKind !== null}
        onOpenChange={(open) => !open && setPromptKind(null)}
        title={
          promptKind === 'link'
            ? 'Insert link'
            : promptKind === 'image'
              ? 'Insert image'
              : 'Embed YouTube video'
        }
        description={
          promptKind === 'link'
            ? 'Enter the URL to link the selected text to. Leave blank to remove the link.'
            : 'Paste the full URL.'
        }
        label="URL"
        placeholder="https://…"
        defaultValue={promptKind === 'link' ? (editor.getAttributes('link').href ?? '') : ''}
        confirmLabel="Insert"
        icon="solar:link-bold-duotone"
        required={false}
        onConfirm={handlePromptConfirm}
      />
    </>
  );
};

/* ── Main component ── */
export interface RichTextEditorProps {
  value: string;
  onChange: (html: string) => void;
  placeholder?: string;
  minHeight?: string;
  className?: string;
  disabled?: boolean;
}

export const RichTextEditor = ({
  value,
  onChange,
  placeholder = 'Start writing…',
  minHeight = '160px',
  className,
  disabled = false,
}: RichTextEditorProps) => {
  const editor = useEditor({
    extensions: [
      StarterKit,
      Underline,
      Link.configure({ openOnClick: false, HTMLAttributes: { class: 'text-primary underline' } }),
      TextAlign.configure({ types: ['heading', 'paragraph'] }),
      TextStyle,
      Color,
      Image.configure({ inline: false, allowBase64: true }),
      Youtube.configure({ controls: true, nocookie: true }),
      Placeholder.configure({ placeholder }),
    ],
    content: value,
    editable: !disabled,
    onUpdate: ({ editor }) => {
      onChange(editor.getHTML());
    },
  });

  // Sync external value changes (e.g. when editing page loads product data)
  useEffect(() => {
    if (!editor) return;
    const current = editor.getHTML();
    if (current !== value) {
      // Only update if value materially differs (avoids cursor jumping)
      editor.commands.setContent(value ?? '', { emitUpdate: false });
    }
  }, [value, editor]);

  return (
    <div
      className={cn(
        'rounded-md border border-input bg-background text-sm',
        'focus-within:ring-2 focus-within:ring-primary/30 focus-within:border-primary',
        'transition-shadow',
        disabled && 'opacity-60 cursor-not-allowed',
        className,
      )}
    >
      {editor && !disabled && <Toolbar editor={editor} />}
      <EditorContent
        editor={editor}
        className={cn(
          'rich-editor-content px-4 py-3 outline-none',
          '[&_.ProseMirror]:outline-none [&_.ProseMirror]:min-h-[var(--editor-min-height)]',
          // Prose styles
          '[&_.ProseMirror_h1]:text-2xl [&_.ProseMirror_h1]:font-bold [&_.ProseMirror_h1]:mb-3 [&_.ProseMirror_h1]:mt-4',
          '[&_.ProseMirror_h2]:text-xl [&_.ProseMirror_h2]:font-semibold [&_.ProseMirror_h2]:mb-2 [&_.ProseMirror_h2]:mt-3',
          '[&_.ProseMirror_h3]:text-lg [&_.ProseMirror_h3]:font-semibold [&_.ProseMirror_h3]:mb-2 [&_.ProseMirror_h3]:mt-3',
          '[&_.ProseMirror_p]:mb-2 [&_.ProseMirror_p.is-editor-empty:first-child::before]:text-muted-foreground',
          '[&_.ProseMirror_ul]:list-disc [&_.ProseMirror_ul]:pl-5 [&_.ProseMirror_ul]:mb-2',
          '[&_.ProseMirror_ol]:list-decimal [&_.ProseMirror_ol]:pl-5 [&_.ProseMirror_ol]:mb-2',
          '[&_.ProseMirror_li]:mb-1',
          '[&_.ProseMirror_a]:text-primary [&_.ProseMirror_a]:underline',
          '[&_.ProseMirror_code]:bg-muted [&_.ProseMirror_code]:px-1 [&_.ProseMirror_code]:rounded [&_.ProseMirror_code]:font-mono [&_.ProseMirror_code]:text-xs',
          '[&_.ProseMirror_pre]:bg-muted [&_.ProseMirror_pre]:rounded [&_.ProseMirror_pre]:p-3 [&_.ProseMirror_pre]:mb-2',
          '[&_.ProseMirror_blockquote]:border-l-4 [&_.ProseMirror_blockquote]:border-primary/40 [&_.ProseMirror_blockquote]:pl-4 [&_.ProseMirror_blockquote]:italic [&_.ProseMirror_blockquote]:mb-2',
          '[&_.ProseMirror_hr]:border-border [&_.ProseMirror_hr]:my-4',
          '[&_.ProseMirror_img]:max-w-full [&_.ProseMirror_img]:rounded-md [&_.ProseMirror_img]:my-2',
          '[&_.ProseMirror_.youtube-video-wrapper]:my-4',
        )}
        style={{ '--editor-min-height': minHeight } as React.CSSProperties}
      />
    </div>
  );
};
