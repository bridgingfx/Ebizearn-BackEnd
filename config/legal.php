<?php

/**
 * Legal — single source of truth for consent-gated content versions.
 *
 * `terms_version` is the CURRENT Terms of Service version every new
 * account must accept. The frontend sends it back in the register and
 * social-signup payloads; AuthController rejects anything that does not
 * match with a 422 asking the user to re-accept.
 *
 * BUMPING THIS VERSION: when the terms text changes, raise the version
 * here. New signups must then accept the new version; accounts whose
 * stored `users.terms_version` is older are asked to re-accept before
 * their pending signup is activated (see OtpController@verify).
 */
return [

    'terms_version' => '1.0',

];
