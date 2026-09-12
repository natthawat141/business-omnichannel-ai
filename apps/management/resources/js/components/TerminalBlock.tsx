import { Tooltip } from "@base-ui/react/tooltip";
import { Tabs } from "@base-ui/react/tabs";
import { Check, Copy, Terminal } from "lucide-react";
import { useEffect, useState } from "react";

/**
 * A reusable, keyboard-accessible code panel for setup guides. The value is
 * copied only after a direct user action; this component never receives keys.
 */
export default function TerminalBlock({
    label,
    value,
    onCopy,
    className = "",
}: {
    label: string;
    value: string;
    onCopy: (value: string) => Promise<boolean>;
    className?: string;
}) {
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (!copied) return;
        const timer = window.setTimeout(() => setCopied(false), 1800);
        return () => window.clearTimeout(timer);
    }, [copied]);

    async function handleCopy() {
        setCopied(await onCopy(value));
    }

    return (
        <div className={`docs-terminal ${className}`}>
            <div className="docs-terminal__bar">
                <span className="docs-terminal__title">
                    <Terminal size={15} aria-hidden="true" />
                    {label}
                </span>
                <Tooltip.Root>
                    <Tooltip.Trigger
                        className="docs-terminal__copy"
                        aria-label={`คัดลอก ${label}`}
                        onClick={handleCopy}
                    >
                        {copied ? (
                            <Check size={14} aria-hidden="true" />
                        ) : (
                            <Copy size={14} aria-hidden="true" />
                        )}
                        <span>{copied ? "Copied" : "Copy"}</span>
                    </Tooltip.Trigger>
                    <Tooltip.Portal>
                        <Tooltip.Positioner sideOffset={8}>
                            <Tooltip.Popup className="docs-tooltip">
                                คัดลอกโดยไม่รวม key
                            </Tooltip.Popup>
                        </Tooltip.Positioner>
                    </Tooltip.Portal>
                </Tooltip.Root>
            </div>
            {className.includes("docs-terminal--prompt") ? <Tabs.Root defaultValue="read" className="prompt-view">
                <Tabs.List aria-label="Prompt display"><Tabs.Tab value="read">Preview</Tabs.Tab><Tabs.Tab value="raw">Markdown</Tabs.Tab></Tabs.List>
                <Tabs.Panel value="read" className="prompt-prose">
                    {value.split(/\n\n+/).map((paragraph, index) => paragraph.startsWith("# ")
                        ? <h3 key={index}>{paragraph.slice(2)}</h3>
                        : paragraph.startsWith("## ") ? <h4 key={index}>{paragraph.slice(3)}</h4>
                        : <p key={index}>{paragraph}</p>)}
                </Tabs.Panel>
                <Tabs.Panel value="raw"><pre tabIndex={0}><code>{value}</code></pre></Tabs.Panel>
            </Tabs.Root> : <pre tabIndex={0}>
                <code>{value}</code>
            </pre>}
        </div>
    );
}
