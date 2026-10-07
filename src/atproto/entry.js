/* Souverain.ovh */
import {
    initAtprotoOAuth,
    loginAtproto,
    publishAtprotoReply,
    getAtprotoProfile,
    logoutAtproto
} from "./oauth.js";


window.FederatedCommentsATProto = {

    init:
        initAtprotoOAuth,

    login:
        loginAtproto,

    publish:
        publishAtprotoReply,

    profile:
        getAtprotoProfile,

    logout:
        logoutAtproto
};
