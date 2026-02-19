<?php

namespace Modules\AI\SubModules;

/**
 * Base Module for AI Chatbot Sub-Modules
 * 
 * Each sub-module focuses on a specific topic (Orders, Loyalty, etc.)
 * and provides its own functions and system prompt.
 */
abstract class BaseModule
{
    /**
     * Get the module identifier
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Get the display name for UI
     *
     * @return string
     */
    abstract public function getDisplayName(): string;

    /**
     * Get the module icon (emoji)
     *
     * @return string
     */
    abstract public function getIcon(): string;

    /**
     * Get the module description
     *
     * @return string
     */
    abstract public function getDescription(): string;

    /**
     * Get the list of function names available in this module
     *
     * @return array<string>
     */
    abstract public function getFunctions(): array;

    /**
     * Get the system prompt for this module
     *
     * @return string
     */
    abstract public function getSystemPrompt(): string;

    /**
     * Get module metadata for API response
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->getName(),
            'display_name' => $this->getDisplayName(),
            'icon' => $this->getIcon(),
            'description' => $this->getDescription(),
            'functions_count' => count($this->getFunctions()),
        ];
    }

    /**
     * Check if this module has a specific function
     *
     * @param string $functionName
     * @return bool
     */
    public function hasFunction(string $functionName): bool
    {
        return in_array($functionName, $this->getFunctions());
    }
}
