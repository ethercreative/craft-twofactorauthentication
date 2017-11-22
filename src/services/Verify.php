<?php
namespace born05\twofactorauthentication\services;

use Craft;
use OTPHP\TOTP;
use yii\base\Component;
use craft\elements\User;
use born05\twofactorauthentication\records\User as UserRecord;
use born05\twofactorauthentication\records\Session as SessionRecord;
use born05\twofactorauthentication\models\AuthenticationCode as AuthenticationCodeModel;
use born05\twofactorauthentication\Plugin as TwoFactorAuth;

class Verify extends Component
{
    private $totp;

    /**
     * Determines if the user has two-factor authentication.
     * @param  User $user
     * @return boolean
     */
    public function isEnabled(User $user)
    {
        $userRecord = UserRecord::findOne([
            'userId' => $user->id,
        ]);

        return isset($userRecord) && $userRecord->dateVerified !== null;
    }

    /**
     * Determines if the user is verified.
     * @param  User $user
     * @return boolean
     */
    public function isVerified(User $user)
    {
        $sessionRecord = SessionRecord::findOne([
            'userId' => $user->id,
            'sessionId' => $this->getSessionId($user),
        ]);

        if (isset($sessionRecord)) {
            $sessionDuration = Craft::$app->config->get('remembereduserDuration');
            $minimalSessionDate = new DateTime();
            $minimalSessionDate->sub(new DateInterval($sessionDuration));

            return $sessionRecord->dateVerified > $minimalSessionDate->format(DateTime::MYSQL_DATETIME);
        }

        return false;
    }

    /**
     * Verify the authenticationCode with the user's credentials.
     * @param  User $user
     * @param  string $authenticationCode
     * @return boolean
     */
    public function verify(User $user, $authenticationCode)
    {
        $authenticationCodeModel = new AuthenticationCodeModel();
        $authenticationCodeModel->authenticationCode = str_replace(' ', '', $authenticationCode);

        if ($authenticationCodeModel->validate()) {
            // Magic checking of the authentication code.
            $isValid = $this->getTotp($user)->verify($authenticationCodeModel->authenticationCode);
            if (!$isValid) {
                return false;
            }

            $userRecord = $this->getUserRecord($user);
            if ($userRecord->dateVerified === null) {
                $userRecord->dateVerified = DateTimeHelper::currentTimeForDb();
                $userRecord->update();
            }

            $twoFactorSessionRecord = $this->getTwoFactorSessionRecord($user);
            $twoFactorSessionRecord->dateVerified = DateTimeHelper::currentTimeForDb();
            $twoFactorSessionRecord->update();

            return true;
        }

        return false;
    }

    /**
     * Disable the current user's two-factor authentication.
     * @param  User $user
     * @return string
     */
    public function disableUser(User $user)
    {
        // Update the user record
        $totp = TOTP::create();
        $userRecord = $this->getUserRecord($user);
        // Remove verified state
        $userRecord->dateVerified = null;
        // Reset the secret
        $userRecord->secret = $totp->getSecret();
        $userRecord->update();

        // Delete the session record
        $twoFactorSessionRecord = $this->getTwoFactorSessionRecord($user);
        $twoFactorSessionRecord->delete();
    }

    /**
     * Get the user's secret.
     * @param  User $user
     * @return string
     */
    public function getUserSecret(User $user)
    {
        return $this->getTotp($user)->getSecret();
    }

    /**
     * Get the user's secret QR code.
     * @param  User $user
     * @return string
     */
    public function getUserQRCode(User $user)
    {
        return $this->getTotp($user)->getQrCodeUri();
    }

    /**
     * Get a valid TOTP instance.
     * @param  User $user
     * @return TOTP
     */
    private function getTotp(User $user) {
        if (!isset($this->totp)) {
            $userRecord = $this->getUserRecord($user);
            $this->totp = TOTP::create($userRecord->secret);
            $this->totp->setLabel($user->email);
            $this->totp->setIssuer(Craft::$app->getSiteName());
        }

        return $this->totp;
    }

    /**
     * Get the user record for two-factor.
     * @param  User $user
     * @return UserRecord
     */
    private function getUserRecord(User $user)
    {
        $userRecord = UserRecord::findOne([
            'userId' => $user->id,
        ]);

        if (!isset($userRecord)) {
            $totp = TOTP::create();
            $userRecord = new UserRecord();
            $userRecord->userId = $user->id;
            $userRecord->secret = $totp->getSecret();
            $userRecord->save();
        }

        return $userRecord;
    }

    /**
     * Get the session record for two-factor.
     * @param  User $user
     * @return SessionRecord
     */
    private function getTwoFactorSessionRecord(User $user)
    {
        $sessionId = $this->getSessionId($user);
        $twoFactorSessionRecord = SessionRecord::findOne([
            'userId' => $user->id,
            'sessionId' => $sessionId,
        ]);

        if (!isset($twoFactorSessionRecord)) {
            $twoFactorSessionRecord = new SessionRecord();
            $twoFactorSessionRecord->userId = $user->id;
            $twoFactorSessionRecord->sessionId = $sessionId;
            $twoFactorSessionRecord->save();
        }

        return $twoFactorSessionRecord;
    }

    /**
     * Get the session id.
     * @param  User $user
     * @return int
     */
    private function getSessionId(User $user)
    {
        $data = Craft::$app->user->getIdentityCookieValue();

        // Data 4 is the UserAgentString.
        if ($data && $this->checkUserAgentString($data[4])) {
            // Data 2 is the session UID.
            $uid = $data[2];

            $sessionRecord = SessionRecord::findOne([
                'userId' => $user->id,
                'uid' => $uid,
            ]);

            if (isset($sessionRecord)) {
                return $sessionRecord->id;
            }
        }

        return null;
    }

    /**
     * Determine if the UserAgent matches the current one.
     *
     * @param string $userAgent
     * @return bool
     */
    private function checkUserAgentString($userAgent)
    {
        if (Craft::$app->config->get('requireMatchingUserAgentForSession')) {
            $currentUserAgent = Craft::$app->request->getUserAgent();

            return $userAgent === $currentUserAgent;
        }

        return true;
    }
}
