<?php
/**
 * @file classes/security/authorization/internal/PublicationRequiredPolicy.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PublicationRequiredPolicy
 * @ingroup security_authorization_internal
 *
 * @brief Policy that ensures that the request contains a valid publication id.
 */

namespace PKP\security\authorization\internal;

use APP\core\Services;
use APP\publication\Publication;

use PKP\security\authorization\AuthorizationPolicy;
use PKP\security\authorization\DataObjectRequiredPolicy;

class PublicationRequiredPolicy extends DataObjectRequiredPolicy
{
    /**
     * Constructor
     *
     * @param $request PKPRequest
     * @param $args array request parameters
     * @param $publicationParameterName string the request parameter we expect
     *  the submission id in.
     * @param null|mixed $operations
     */
    public function __construct($request, &$args, $publicationParameterName = 'publicationId', $operations = null)
    {
        parent::__construct($request, $args, $publicationParameterName, 'user.authorization.invalidPublication', $operations);

        $callOnDeny = [$request->getDispatcher(), 'handle404', []];
        $this->setAdvice(
            AuthorizationPolicy::AUTHORIZATION_ADVICE_CALL_ON_DENY,
            $callOnDeny
        );
    }

    //
    // Implement template methods from AuthorizationPolicy
    //
    /**
     * @see DataObjectRequiredPolicy::dataObjectEffect()
     */
    public function dataObjectEffect()
    {
        // Get the publication id.
        $publicationId = $this->getDataObjectId();
        if ($publicationId === false) {
            return AuthorizationPolicy::AUTHORIZATION_DENY;
        }

        $publication = Services::get('publication')->get($publicationId);
        if (!$publication instanceof Publication) {
            return AuthorizationPolicy::AUTHORIZATION_DENY;
        }

        // Save the publication to the authorization context.
        $this->addAuthorizedContextObject(ASSOC_TYPE_PUBLICATION, $publication);
        return AuthorizationPolicy::AUTHORIZATION_PERMIT;
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\PKP\security\authorization\internal\PublicationRequiredPolicy', '\PublicationRequiredPolicy');
}
