<?php

/**
 * Words that must never appear in campaign content contributors copy and
 * post (AI-generated or typed by a business). Matched as whole words after
 * normalising look-alike characters (sh1t, f*ck, a$$ …), on top of the
 * OpenAI moderation check in App\Services\AI\ContentSafety, which judges
 * context (violence, harassment, hate, sexual content …).
 *
 * Only clearly offensive words belong here. Words with everyday meanings
 * ("shoot", "kill germs", "crackers", "hang out") are left to the
 * moderation check so normal marketing copy is not blocked.
 */
return [
    'blocked_terms' => [
        // Profanity
        'fuck', 'fucker', 'fuckers', 'fucking', 'fucked', 'motherfucker', 'fck', 'fuk', 'fuking', 'wtf', 'stfu',
        'shit', 'shitty', 'bullshit', 'shithead', 'goddamn',
        'asshole', 'arsehole', 'jackass', 'dumbass',
        'bitch', 'bitches', 'bastard', 'dickhead', 'cunt', 'twat',
        'bollocks', 'wanker', 'douchebag',
        // Insults
        'idiot', 'idiots', 'moron', 'morons', 'imbecile', 'retard', 'retarded',
        // Sexual
        'porn', 'porno', 'pornography', 'nudes', 'boobs', 'tits', 'pussy',
        'horny', 'slut', 'sluts', 'whore', 'whores', 'xxx', 'onlyfans',
        // Hate / slurs
        'nigger', 'nigga', 'faggot', 'fag', 'dyke', 'tranny', 'chink', 'spic', 'kike',
        'raghead', 'towelhead', 'wetback', 'gook', 'beaner',
        // Common Tamil / Hindi abuse written in English letters
        'punda', 'pundai', 'thevidiya', 'thevudiya', 'koothi',
        'madarchod', 'behenchod', 'bhenchod', 'chutiya', 'chutiye', 'bhosdike', 'randi',
    ],
];
