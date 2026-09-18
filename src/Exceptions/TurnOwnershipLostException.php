<?php

namespace EasyAI\LaravelVoice\Exceptions;

/**
 * Thrown when this execution discovers, mid-turn, that another execution
 * has already reclaimed the turn it was working on (see VoiceAgent::
 * guardedTurnUpdate()/stillOwnsTurn()). A plain VoiceException - callers
 * that only catch that base type still get a clean 409 through
 * VoiceTurnController's existing exception mapping; nothing new is
 * exposed over HTTP.
 */
class TurnOwnershipLostException extends VoiceException {}
