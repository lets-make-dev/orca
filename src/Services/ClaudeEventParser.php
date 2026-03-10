<?php

namespace MakeDev\Orca\Services;

class ClaudeEventParser
{
    /**
     * Classify a Claude stream-json NDJSON event into a message type.
     */
    public function classify(array $event): string
    {
        $type = $event['type'] ?? '';

        return match (true) {
            $type === 'assistant' && $this->isToolUseEvent($event) => 'tool_use',
            $type === 'assistant' => 'text',
            $type === 'user' => 'text',
            $type === 'result' => 'system',
            $type === 'system' => 'system',
            $type === 'rate_limit_event' => 'system',
            default => 'system',
        };
    }

    /**
     * Extract human-readable display content from a Claude event.
     */
    public function extractDisplayContent(array $event): string
    {
        $type = $event['type'] ?? '';

        if ($type === 'assistant') {
            return $this->extractAssistantContent($event);
        }

        if ($type === 'result') {
            return '';
        }

        if ($type === 'system') {
            return $event['message'] ?? '';
        }

        return '';
    }

    /**
     * Extract metadata from a Claude event (tool names, question options, etc.).
     *
     * @return array<string, mixed>
     */
    public function extractMetadata(array $event): array
    {
        $metadata = [];

        if ($this->isToolUseEvent($event)) {
            $toolUse = $this->findToolUseBlock($event);
            if ($toolUse) {
                $metadata['tool'] = $toolUse['name'] ?? null;
                $metadata['tool_use_id'] = $toolUse['id'] ?? null;
            }
        }

        if ($this->isAskUserQuestion($event)) {
            $toolUse = $this->findToolUseBlock($event);
            $input = $toolUse['input'] ?? [];
            $metadata['interaction_type'] = 'question';
            $metadata['questions'] = $input['questions'] ?? [];
        }

        if ($this->isPermissionRequest($event)) {
            $metadata['interaction_type'] = 'permission';
            $toolUse = $this->findToolUseBlock($event);
            if ($toolUse) {
                $metadata['tool'] = $toolUse['name'] ?? null;
                $metadata['permission_input'] = $toolUse['input'] ?? [];
            }
        }

        $metadata['subtype'] = $event['subtype'] ?? null;

        return array_filter($metadata, fn ($v) => $v !== null);
    }

    /**
     * Determine if a Claude event requires user interaction (question or permission).
     */
    public function isInteractionRequired(array $event): bool
    {
        return $this->isAskUserQuestion($event) || $this->isPermissionRequest($event);
    }

    /**
     * Build an NDJSON stdin payload to send a user response back to Claude.
     */
    public function buildStdinPayload(string $response): string
    {
        return json_encode([
            'type' => 'user',
            'message' => [
                'role' => 'user',
                'content' => $response,
            ],
        ], JSON_UNESCAPED_SLASHES)."\n";
    }

    /**
     * Build an NDJSON stdin payload for a permission response.
     */
    public function buildPermissionPayload(bool $approved): string
    {
        return json_encode([
            'type' => 'user',
            'message' => [
                'role' => 'user',
                'content' => $approved ? 'yes' : 'no',
            ],
        ], JSON_UNESCAPED_SLASHES)."\n";
    }

    private function isToolUseEvent(array $event): bool
    {
        $content = $event['message']['content'] ?? [];

        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'tool_use') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findToolUseBlock(array $event): ?array
    {
        $content = $event['message']['content'] ?? [];

        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'tool_use') {
                return $block;
            }
        }

        return null;
    }

    private function isAskUserQuestion(array $event): bool
    {
        $toolUse = $this->findToolUseBlock($event);

        return $toolUse && ($toolUse['name'] ?? '') === 'AskUserQuestion';
    }

    private function isPermissionRequest(array $event): bool
    {
        return ($event['type'] ?? '') === 'assistant'
            && ($event['subtype'] ?? '') === 'permission_request';
    }

    private function extractAssistantContent(array $event): string
    {
        $content = $event['message']['content'] ?? [];
        $parts = [];

        foreach ($content as $block) {
            $blockType = $block['type'] ?? '';

            if ($blockType === 'text') {
                $parts[] = $block['text'] ?? '';
            } elseif ($blockType === 'tool_use') {
                $name = $block['name'] ?? 'unknown';
                $parts[] = "[Tool: {$name}]";
            }
        }

        return implode("\n", array_filter($parts));
    }
}
