/* Souverain.ovh */
import {
    BrowserOAuthClient
} from "@atproto/oauth-client-browser";

import {
    Agent
} from "@atproto/api";


const currentScript =
    document.currentScript;

if (!currentScript?.src) {
    throw new Error(
        "Impossible de déterminer l’URL du bundle ATProto."
    );
}

const CLIENT_ID =
    new URL(
        "oauth-client-metadata.php",
        currentScript.src
    ).href;


let client = null;
let session = null;
let agent = null;


export async function initAtprotoOAuth() {

    client =
        await BrowserOAuthClient.load({
            clientId:
                CLIENT_ID,

            handleResolver:
                "https://bsky.social/"
        });


    const result =
        await client.init();


    if (result?.session) {

        session =
            result.session;

        agent =
            new Agent(
                session
            );


        return {
            connected:
                true,

            did:
                session.did
        };
    }


    return {
        connected:
            false
    };
}


export async function loginAtproto(
    handle
) {

    if (!client) {
        throw new Error(
            "Client OAuth non initialisé."
        );
    }


    await client.signIn(
        handle,
        {
            signal:
                AbortSignal.timeout(
                    10000
                )
        }
    );
}


export async function getAtprotoProfile() {

    if (
        !agent ||
        !session
    ) {
        throw new Error(
            "Aucune session ATProto active."
        );
    }


    const result =
        await agent.getProfile({
            actor:
                session.did
        });


    return {
        did:
            session.did,

        handle:
            result.data.handle,

        displayName:
            result.data.displayName
            ||
            "",

        avatar:
            result.data.avatar
            ||
            ""
    };
}


export async function logoutAtproto() {

    if (!session) {
        return {
            disconnected:
                true
        };
    }


    await session.signOut();


    session =
        null;

    agent =
        null;


    return {
        disconnected:
            true
    };
}


export async function publishAtprotoReply(
    text,
    rootPost
) {

    if (
        !agent ||
        !session
    ) {
        throw new Error(
            "Aucune session ATProto active."
        );
    }


    text =
        String(
            text || ""
        ).trim();


    if (!text) {
        throw new Error(
            "La réponse est vide."
        );
    }


    if (
        !rootPost ||
        !rootPost.uri ||
        !rootPost.cid
    ) {
        throw new Error(
            "La publication ATProto racine est introuvable."
        );
    }


    const result =
        await agent.post({
            text,

            reply: {

                root: {
                    uri:
                        rootPost.uri,

                    cid:
                        rootPost.cid
                },

                parent: {
                    uri:
                        rootPost.uri,

                    cid:
                        rootPost.cid
                }
            },

            createdAt:
                new Date()
                    .toISOString()
        });


    return {
        uri:
            result.uri,

        cid:
            result.cid
    };
}
