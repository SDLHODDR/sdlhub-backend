require("dotenv").config();

// const TelegramBot = require("node-telegram-bot-api");
// const axios = require("axios");

// const bot = new TelegramBot(
//   process.env.BOT_TOKEN,
//   {
//     polling: true,
//   }
// );

require("dotenv").config();
const axios = require("axios");
const bot = require(
  "./telegramClient"
);

bot.getMe().then((me) => {
  console.log("BOT INFO:", me);
});

bot.on("polling_error", (error) => {
  console.log("POLLING ERROR");
  console.log(error);
  if (error.response) {
    console.error("Response:");
    console.error(error.response.body);
  }
});

// bot.on("webhook_error", (error) => {
//   console.log("WEBHOOK ERROR");
//   console.log(error);
// });

bot.on("message", (msg) => {
  console.log("FULL MESSAGE");
  console.log(JSON.stringify(msg, null, 2));
});

console.log("Bot Started...");

// bot.on("message", async (msg) => {
//   console.log("MESSAGE:", msg.text);

//   if (!msg.text?.startsWith("/start")) {
//     return;
//   }

//   const parts = msg.text.split(" ");

//   if (parts.length < 2) {
//     return bot.sendMessage(
//       msg.chat.id,
//       "Missing parameters"
//     );
//   }

//   const startParam = parts[1];

//   const [empCode, groupId] =
//     startParam.split("~");

//   console.log("EMP CODE:", empCode);
//   console.log("GROUP ID:", groupId);
//   console.log("USER ID:", msg.from.id);
// });

bot.onText(/\/start(?:\s+(.+))?/, async (msg, match) => {
  const startParam = match?.[1];

  if (!startParam) {
    return bot.sendMessage(
      msg.chat.id,
      "Invalid link."
    );
  }

  const chatId = msg.chat.id;
  //const empCode = match[1];
  const [empCode, groupId] = startParam.split("_");

  console.log("Message:", msg);
  console.log("Chat ID:", chatId);
  console.log("Emp Code:", empCode);
  console.log("Group Code:", groupId);
  console.log( "START RECEIVED", empCode, chatId, groupId );
  


  if (!empCode) {
    return bot.sendMessage(
      chatId,
      "Invalid invite link."
    );
  }

  console.log("=========URL===========", process.env.API_URL);

  try {
    const response = await axios.post(
      "http://localhost:8080/sdlhub-backend/api/psr_telegram/api/telegram/members/mapuser-group",
      {
        emp_code: empCode,
        group_id: groupId,
        telegram_chat_id: chatId,
      }
    );

  //   // const groups = response.data.data || [];

  //   // console.log(response.data);

  //   // let message = "Welcome.\n\nYour Group Links:\n\n";
  //   // message += groups.length;

  //   // groups.forEach((group, index) => {
  //   //   message += `${index + 1}. ${
  //   //     group.GROUP_NAME
  //   //   }\n${group.INVITE_LINK}\n\n`;
  //   // });

  //   // bot.sendMessage(chatId, message);

    const groups = response.data?.data?.data?.groups || [];
    let message = "Welcome.\n\nYour Group Links:\n\n";

    groups.forEach((group, index) => {
      message += `${index + 1}. ${group.GROUP_NAME}\n`;
      group.INVITE_LINK.forEach((link) => {
        message += `${link.INVITE_LINK}\n`;
      });
      message += "\n";
    });

    bot.sendMessage(chatId, message);
  } catch (error) {
    console.error(error);

    bot.sendMessage(
      chatId,
      "Error fetching group links."
    );
  }
});