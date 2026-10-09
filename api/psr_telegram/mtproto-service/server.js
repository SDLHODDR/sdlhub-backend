const express = require("express");
const cors = require("cors");
const axios = require("axios");
const path = require("node:path");

process.loadEnvFile(path.resolve(__dirname, "../../../.env"));

const { TelegramClient } = require("telegram");
const { StringSession } = require("telegram/sessions");
const { Api } = require("telegram");
//const { NewMessage } = require("telegram/events");

const input = require("input");

const app = express();

app.use(cors());
app.use(express.json());

const apiId = Number(process.env.TELEGRAM_API_ID);
const apiHash = process.env.TELEGRAM_API_HASH;
const telegramSession = process.env.TELEGRAM_SESSION || "";
const port = Number(process.env.TELEGRAM_PORT || 5001);
const groupJoinedCallbackUrl =
  process.env.TELEGRAM_GROUP_JOINED_CALLBACK_URL ||
  "http://localhost:8080/sdlhub/sdlhub_new/backend/api/psr_telegram/api/telegram/members/group-joined";

if (!Number.isInteger(apiId) || apiId <= 0) {
  throw new Error("TELEGRAM_API_ID must be set to a positive integer in sdlhub-backend/.env");
}
if (!apiHash) {
  throw new Error("TELEGRAM_API_HASH must be set in sdlhub-backend/.env");
}
if (!Number.isInteger(port) || port <= 0 || port > 65535) {
  throw new Error("TELEGRAM_PORT must be a valid port number");
}

const stringSession = new StringSession(telegramSession);

const client = new TelegramClient(
  stringSession,
  apiId,
  apiHash,
  {
    connectionRetries: 5,
    receiveUpdates: true,
  }
);

async function initTelegram() {
  await client.start({
    phoneNumber: async () => await input.text("Phone: "),
    password: async () => await input.text("Password: "),
    phoneCode: async () => await input.text("Code: "),
    onError: (err) => console.log(err),
  });

  await client.connect();

  console.log("Telegram Connected");

  console.log(client.session.save());

  // REGISTER EVENTS
  registerTelegramEvents();

  console.log("Telegram Events Registered")
}

initTelegram();



function registerTelegramEvents() {
  client.addEventHandler(async (update) => {
    try {
      if (update.className !== "UpdateNewChannelMessage") {
        return;
      }

      const message = update.message;
      if (!message?.action) {
        return;
      }

      let userId = null;
      if ( message.action.className === "MessageActionChatJoinedByLink" ) {
        userId = message.fromId?.userId;
      }

      if ( message.action.className === "MessageActionChatAddUser" ) {
        userId = message.action.users?.[0];
      }

      if (!userId) {
        return;
      }

      const user = await client.getEntity(userId);
      const group = await client.getEntity(message.peerId);

      const telegramUserId = user.id.toString();
      const telegramGroupId = group.id.toString();

      console.log("USER JOINED", {
        telegramUserId,
        telegramGroupId,
        firstName: user.firstName,
        lastName: user.lastName,
      });

      try {
        const response = await axios.post(
          groupJoinedCallbackUrl,
          {
            telegram_user_id: telegramUserId,
            telegram_group_id: telegramGroupId
          }
        );

        console.log( "GROUP JOIN UPDATED", response.data );
      } catch (err) {
        console.log( "GROUP JOIN API ERROR", err.message );
      }
    } catch (err) {
      console.log("EVENT ERROR:", err);
    }
  });
}

app.post("/create-group", async (req, res) => {
  try {
    console.log("=======Req body", req.body);

    const { group_name, description } = req.body;

    if (!group_name) {
      return res.status(400).json({
        success: false,
        message: "group_name is required",
      });
    }

    // Create Supergroup
    const result = await client.invoke(
      new Api.channels.CreateChannel({
        title: group_name,
        about: description || "",
        megagroup: true,
      })
    );

    const groupEntity = result.chats[0];

    // Export Invite Link
    const invite = await client.invoke(
      new Api.messages.ExportChatInvite({
        peer: groupEntity,
      })
    );

    // Get Bot Entity
    const botEntity = await client.getEntity("sdlitTechBot");

    // Add Bot to Group
    await client.invoke(
      new Api.channels.InviteToChannel({
        channel: groupEntity,
        users: [botEntity],
      })
    );

    await client.invoke(
      new Api.channels.EditAdmin({
        channel: groupEntity,
        userId: botEntity,
        adminRights: new Api.ChatAdminRights({
          sendMessages: true,
          inviteUsers: true,
          pinMessages: true,
          manageTopics: true,
        }),
        rank: "Bot",
      })
    );

    // Set default banned rights
    await client.invoke(
      new Api.messages.EditChatDefaultBannedRights({
        peer: groupEntity,
        bannedRights: new Api.ChatBannedRights({
          untilDate: 0,

          sendMessages: true,
          sendMedia: true,
          sendStickers: true,
          sendGifs: true,
          sendGames: true,
          sendInline: true,
          embedLinks: true,
          sendPolls: true,
        }),
      })
    );

    res.json({
      success: true,
      data: {
        telegram_group_id: groupEntity.id,
        title: groupEntity.title,
        invite_link: invite.link,
      },
    });

  } catch (err) {
    console.log(err);

    res.status(500).json({
      success: false,
      message: err.message,
    });
  }
});


app.post("/generate-employee-invite", async (req, res) => {
  try {

    const {
      telegram_group_id,
      employee_code
    } = req.body;

    const groupEntity = await client.getEntity(
      Number(telegram_group_id)
    );

    const invite = await client.invoke(
      new Api.messages.ExportChatInvite({
        peer: groupEntity,

        // one employee = one link
        usageLimit: 1,

        // optional expiry after 7 days
        expireDate:
          Math.floor(Date.now() / 1000) +
          (7 * 24 * 60 * 60)
      })
    );

    const inviteLink = invite.link;

    const inviteHash =
      inviteLink.includes("+")
        ? inviteLink.split("+")[1]
        : inviteLink;

    console.log("EMPLOYEE:", employee_code);
    console.log("INVITE:", inviteLink);

    res.json({
      success: true,
      data: {
        employee_code,
        telegram_group_id,
        invite_link: inviteLink,
        invite_hash: inviteHash
      }
    });

  } catch (err) {

    console.log(err);

    res.status(500).json({
      success: false,
      message: err.message
    });

  }
});

app.listen(port, () => {
  console.log(`MTProto Service Running on ${port}`);
});

process.stdin.resume();